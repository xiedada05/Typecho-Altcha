<?php
/**
 * 端到端测试: 对真实 Typecho 站点的评论接口做完整验证 (数据库适配器无关)
 *
 * 用法: php tests/e2e.php [config.inc.php路径]
 * 默认使用 ../typecho/config.inc.php (当前站点所用数据库)
 *
 * 说明:
 * - 站点密钥 (插件 HMAC / 反垃圾 secret) 从 options 表动态读取, 重装后无需改脚本;
 * - Typecho core 对同 IP 评论有频次节流, 每次成功入库后删除测试评论以重置窗口;
 * - 退出码 0 = 全部通过。
 */

$configFile = $argv[1] ?? dirname(__DIR__) . '/../typecho/config.inc.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "站点配置文件不存在: {$configFile}\n");
    exit(2);
}

// 引导站点 (定义 __TYPECHO_ROOT_DIR__ 并完成 Common::init + Db::set)
require $configFile;

require __DIR__ . '/../Altcha/autoload.php';

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;

$db = \Typecho\Db::get();

/** 从 options 表读取站点配置值 */
function readOption(string $name): string
{
    global $db;
    $row = $db->fetchRow(
        $db->select()->from('table.options')->where('user = 0 AND name = ?', $name)
    );
    return (string) ($row['value'] ?? '');
}

$siteSecret = '';
foreach (json_decode(readOption('plugin:Altcha'), true) ?: [] as $key => $value) {
    if ('hmacSecret' === $key) {
        $siteSecret = (string) $value;
    }
}
$antiSpamSecret = readOption('secret');
$baseUrl = rtrim(readOption('siteUrl'), '/') ?: 'http://127.0.0.1:8088';
$postUrl = $baseUrl . '/index.php/archives/1/comment';
$referer = $baseUrl . '/index.php/archives/1/';

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . "  {$name}" . ($cond ? '' : "  [{$detail}]") . "\n";
    if (!$cond) {
        $failures++;
    }
}

/** 删除测试文章的全部评论并同步计数, 同时重置 core 的 IP 频次节流 (含安装器欢迎评论) */
function cleanupTestComments(): void
{
    global $db;
    $db->query($db->delete('table.comments')->where('cid = ?', 1));
    $db->query($db->update('table.contents')->rows(['commentsNum' => 0])->where('cid = ?', 1));
}

/** 从错误页提取服务端消息 (Common::error 渲染在 container div 中) */
function msgOf(string $body): string
{
    preg_match('/<div class="container">(.*?)<\/div>/s', $body, $m);
    return isset($m[1]) ? trim(strip_tags($m[1])) : substr(strip_tags($body), 0, 100);
}

/** 模拟浏览器 POST 评论, 返回 [状态码, 响应体] (不跟随重定向) */
function postComment(?string $altcha, string $text = '端到端测试评论'): array
{
    global $antiSpamSecret, $postUrl, $referer;
    $fields = [
        'author' => 'E2E测试者',
        'mail'   => 'e2e@example.com',
        'url'    => '',
        'text'   => $text,
        '_'      => md5($antiSpamSecret . '&' . $referer),
    ];
    if (null !== $altcha) {
        $fields['altcha'] = $altcha;
    }
    $context = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Referer: {$referer}\r\nContent-Type: application/x-www-form-urlencoded\r\nUser-Agent: AltchaE2E/1.0\r\n",
        'content' => http_build_query($fields),
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);
    $body = file_get_contents($postUrl, false, $context);
    preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), (string) $body];
}

/** 从站点挑战端点获取挑战并解题, 返回表单 payload */
function solveFromEndpoint(): string
{
    global $siteSecret, $baseUrl;
    $challenge = Challenge::fromArray(json_decode(file_get_contents($baseUrl . '/index.php/action/altcha'), true));
    $altcha = new Altcha($siteSecret, $siteSecret);
    $solution = $altcha->solveChallenge(new SolveChallengeOptions(
        challenge: $challenge,
        algorithm: new Pbkdf2(),
        timeout: 60.0,
    ));
    if (null === $solution) {
        throw new RuntimeException('挑战解题失败');
    }
    return (new Payload($challenge, $solution))->toBase64();
}

echo "适配器: ", $db->getAdapterName(), "\n";
echo "站点: ", $baseUrl, "\n\n";

if (empty($siteSecret)) {
    fwrite(STDERR, "未读取到插件 HMAC 密钥, 插件是否已启用并保存配置?\n");
    exit(2);
}

cleanupTestComments();

// 场景①: 从端点获取挑战 → 解题 → 提交 → 评论应成功 (302 回文章页)
$payload = solveFromEndpoint();
[$status, $body] = postComment($payload, '场景①: 合理解题后提交的评论 ' . time());
check('合理解题的评论提交成功 (302)', 302 === $status, "status={$status}");

$index = file_get_contents($referer);
check('评论出现在文章页', false !== strpos($index, '合理解题后提交的评论'));
cleanupTestComments();

// 场景②: 无 altcha → 403 请先完成人机验证
[$status, $body] = postComment(null, '不应入库的评论');
check('无验证提交被拒绝 (403)', 403 === $status, "status={$status}");
check('错误消息为「请先完成人机验证」', false !== strpos($body, '请先完成人机验证'), msgOf($body));

// 场景③: 篡改 counter → 403
$tampered = json_decode(base64_decode($payload), true);
$tampered['solution']['counter'] = 123456;
[$status, $body] = postComment(base64_encode(json_encode($tampered)), '篡改提交');
check('篡改 counter 被拒绝 (403)', 403 === $status, "status={$status}");
check('错误消息为「未通过」', false !== strpos($body, '人机验证未通过'), msgOf($body));

// 场景④: 过期挑战 → 403 验证已过期
$altcha = new Altcha($siteSecret, $siteSecret);
$expired = $altcha->createChallenge(new AltchaOrg\Altcha\CreateChallengeOptions(
    algorithm: new Pbkdf2(),
    cost: 100,
    keyPrefixLength: 1,
    counter: 3,
    expiresAt: time() - 100,
));
$expiredSolution = $altcha->solveChallenge(new SolveChallengeOptions(challenge: $expired, algorithm: new Pbkdf2()));
$expiredPayload = (new Payload($expired, $expiredSolution))->toBase64();
[$status, $body] = postComment($expiredPayload, '过期挑战提交');
check('过期挑战被拒绝 (403)', 403 === $status, "status={$status}");
check('错误消息为「验证已过期」', false !== strpos($body, '验证已过期'), msgOf($body));

// 场景⑤: 重放同一 payload → 必须被一次性保护拒绝
[$status, $body] = postComment($payload, '重放测试 ' . time());
check('重放 payload 被拒绝 (403)', 403 === $status, "status={$status}");
check('错误消息为「已被使用」', false !== strpos($body, '已被使用'), msgOf($body));

cleanupTestComments();
echo $failures === 0 ? "\n全部通过\n" : "\n{$failures} 项失败\n";
exit($failures === 0 ? 0 : 1);
