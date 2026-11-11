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
require_once __DIR__ . '/../Altcha/Plugin.php';

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use TypechoPlugin\Altcha\Plugin;

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

/** 模拟浏览器 POST 评论, 返回 [状态码, 响应体] (不跟随重定向); $altcha 可为数组以测试畸形输入 */
function postComment($altcha, string $text = '端到端测试评论'): array
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

/**
 * 从站点挑战端点获取挑战并解题
 *
 * @return array{0: Challenge, 1: string} [挑战对象, 表单 payload]
 */
function solveFromEndpoint(): array
{
    global $siteSecret, $baseUrl;
    $challenge = Challenge::fromArray(json_decode(file_get_contents($baseUrl . '/index.php/action/altcha'), true));
    $altcha = new Altcha($siteSecret, $siteSecret);
    $solution = $altcha->solveChallenge(new SolveChallengeOptions(
        challenge: $challenge,
        algorithm: Plugin::algorithm($challenge->parameters->algorithm),
        timeout: 60.0,
    ));
    if (null === $solution) {
        throw new RuntimeException('挑战解题失败');
    }
    return [$challenge, (new Payload($challenge, $solution))->toBase64()];
}

/**
 * 生成同一解答的等价字节变体: 语义 (签名 / counter / derivedKey) 不变,
 * 但原始 base64 字符串不同 (time / 未知键 / 键序), 验证结果应完全相同。
 *
 * @param array<string, mixed> $extra 追加到 payload 根部的未知键
 */
function payloadVariant(string $payload, ?float $time = null, array $extra = []): string
{
    $data = json_decode(base64_decode($payload, true), true, 512, JSON_THROW_ON_ERROR);
    if (null !== $time) {
        $data['solution']['time'] = $time;
    }
    foreach ($extra as $key => $value) {
        $data[$key] = $value;
    }

    return base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** 为同一个挑战求另一个独立合法解答 (counter 从 $start 开始扫描) */
function solveSameChallenge(Challenge $challenge, int $start): string
{
    global $siteSecret;
    $altcha = new Altcha($siteSecret, $siteSecret);
    $solution = $altcha->solveChallenge(new SolveChallengeOptions(
        challenge: $challenge,
        algorithm: Plugin::algorithm($challenge->parameters->algorithm),
        start: $start,
        timeout: 60.0,
    ));
    if (null === $solution) {
        throw new RuntimeException('第二个合法解答求解失败');
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
[$challenge, $payload] = solveFromEndpoint();
check('签发的挑战不再携带 keySignature (快路径已关闭)', null === $challenge->parameters->keySignature);
check(
    '签发的挑战使用随机 1 字节 keyPrefix',
    1 === preg_match('/^[0-9a-f]{2}$/', $challenge->parameters->keyPrefix),
    'keyPrefix=' . $challenge->parameters->keyPrefix
);
[$status, $body] = postComment($payload, '场景①: 合理解题后提交的评论 ' . time());
check('合理解题的评论提交成功 (302)', 302 === $status, "status={$status}");

$index = file_get_contents($referer);
check('评论出现在文章页', false !== strpos($index, '合理解题后提交的评论'));
cleanupTestComments();

// 场景②: 无 altcha → 403 请先完成人机验证
[$status, $body] = postComment(null, '不应入库的评论');
check('无验证提交被拒绝 (403)', 403 === $status, "status={$status}");
check('错误消息为「请先完成人机验证」', false !== strpos($body, '请先完成人机验证'), msgOf($body));

// 场景②-1: 数组型 altcha[] → 必须被干净拒绝 (403), 而不是类型错误 500
cleanupTestComments();
[$status, $body] = postComment(['not-a-string'], '数组型 altcha 提交');
check('数组型 altcha 被拒绝 (403, 非 500)', 403 === $status, "status={$status}");
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

// 场景⑤-1: 等价字节变体 (solution.time / 未知键) — 验证通过但不得获得第二次消费配额
// 修复前: 指纹取自原始字节, 变体与原始 payload 各占一个新主键, 可无限提交
cleanupTestComments();
[$variantChallenge, $variantPayload] = solveFromEndpoint();
$variant = payloadVariant($variantPayload, 1.5, ['junk' => [1, ['a' => 2]], 'zzz' => null]);
[$status, $body] = postComment($variant, '字节变体首次提交 ' . time());
check('等价字节变体仍能通过验证并首次提交 (302)', 302 === $status, "status={$status} " . msgOf($body));
cleanupTestComments();
[$status, $body] = postComment($variantPayload, '原始 payload 在变体之后重放');
check('同一解答的原始字节被拒绝 (403)', 403 === $status, "status={$status}");
check('拒绝消息为「已被使用」', false !== strpos($body, '已被使用'), msgOf($body));
cleanupTestComments();
$variant2 = payloadVariant($variantPayload, 9.25, ['another' => 'x']);
[$status, $body] = postComment($variant2, '第二个字节变体重放');
check('同一解答的第二个字节变体被拒绝 (403)', 403 === $status, "status={$status}");
check('拒绝消息为「已被使用」', false !== strpos($body, '已被使用'), msgOf($body));

// 场景⑤-2: 同一挑战的第二个独立合法解答 — 必须与第一个解答共享一次消费配额
// 修复前: 每份解答各有独立指纹, 一份已签发挑战可授权无限次提交
cleanupTestComments();
[$multiChallenge, $multiPayload] = solveFromEndpoint();
$firstCounter = Payload::fromBase64($multiPayload)->solution->counter;
[$status, $body] = postComment($multiPayload, '同一挑战第一个解答 ' . time());
check('同一挑战的第一个解答提交成功 (302)', 302 === $status, "status={$status}");
$secondPayload = solveSameChallenge($multiChallenge, $firstCounter + 1);
cleanupTestComments();
[$status, $body] = postComment($secondPayload, '同一挑战第二个解答');
check('同一挑战的第二个独立解答被拒绝 (403)', 403 === $status, "status={$status}");
check('拒绝消息为「已被使用」', false !== strpos($body, '已被使用'), msgOf($body));

cleanupTestComments();

// 场景⑤-3: XML-RPC 识别只能基于端点路径, 查询串不得影响授权判定
// 修复前: <permalink>/comment?action=xmlrpc 会被误判为 XML-RPC 调用
$isXmlRpc = new ReflectionMethod(Plugin::class, 'isXmlRpcUrl');
$isXmlRpc->setAccessible(true);
foreach ([
    ['http://127.0.0.1:8088/index.php/action/xmlrpc', true],
    ['http://127.0.0.1:8088/index.php/action/xmlrpc?foo=1', true],
    ['http://127.0.0.1:8088/index.php/action/xmlrpc/', true],
    ['http://127.0.0.1:8088/index.php/archives/1/comment?action=xmlrpc', false],
    ['http://127.0.0.1:8088/index.php/archives/1/comment?foo=1&action=xmlrpc', false],
    ['http://127.0.0.1:8088/index.php/archives/1/comment?Action=XMLRPC', false],
    ['http://127.0.0.1:8088/index.php/archives/1/comment', false],
] as [$url, $expected]) {
    check(
        'isXmlRpcUrl(' . $url . ') = ' . ($expected ? 'true' : 'false'),
        $expected === $isXmlRpc->invoke(null, $url)
    );
}

// ===== XML-RPC 豁免场景 =====

/** 手工构造 XML-RPC 调用 (支持 string/int/struct), 返回响应原文 */
function xmlRpcCall(string $method, array $params): string
{
    global $baseUrl;
    $value = function ($v) use (&$value): string {
        if (is_int($v)) {
            return '<value><int>' . $v . '</int></value>';
        }
        if (is_array($v)) {
            $members = '';
            foreach ($v as $k => $item) {
                $members .= '<member><name>' . htmlspecialchars((string) $k) . '</name>' . $value($item) . '</member>';
            }
            return '<value><struct>' . $members . '</struct></value>';
        }
        return '<value><string>' . htmlspecialchars((string) $v) . '</string></value>';
    };

    $xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($method)
        . '</methodName><params>';
    foreach ($params as $p) {
        $xml .= '<param>' . $value($p) . '</param>';
    }
    $xml .= '</params></methodCall>';

    $context = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: text/xml\r\nUser-Agent: AltchaE2E/1.0\r\n",
        'content' => $xml,
        'ignore_errors' => true,
    ]]);
    return (string) @file_get_contents($baseUrl . '/index.php/action/xmlrpc', false, $context);
}

/** 修改插件配置项 (写 options 表 plugin:Altcha JSON) */
function setPluginConfig(array $kv): void
{
    global $db;
    $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:Altcha'));
    $cfg = json_decode((string) $row['value'], true) ?: [];
    foreach ($kv as $k => $v) {
        $cfg[$k] = $v;
    }
    $db->query($db->update('table.options')
        ->rows(['value' => json_encode($cfg)])
        ->where('name = ?', 'plugin:Altcha'));
}

/** 读/写站点选项 */
function siteOption(string $name, ?string $value = null): string
{
    global $db;
    if (null !== $value) {
        $db->query($db->update('table.options')->rows(['value' => $value])->where('name = ?', $name));
    }
    $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', $name));
    return (string) ($row['value'] ?? '');
}

$antiSpamBackup = siteOption('commentsAntiSpam');

// 场景⑥: 未开启豁免时, XML-RPC 登录 (无 altcha 字段) 必被拦截
$resp = xmlRpcCall('blogger.getUsersBlogs', [1, 'admin', 'Altcha#2026@Test']);
check('未豁免时 XML-RPC 登录被拦截', false !== strpos($resp, '<fault>'), substr($resp, 0, 120));

// 场景⑦: 开启豁免 + 名单含 admin → XML-RPC 登录放行
setPluginConfig(['xmlrpcBypass' => 'enable', 'xmlrpcUsers' => 'admin']);
$resp = xmlRpcCall('blogger.getUsersBlogs', [1, 'admin', 'Altcha#2026@Test']);
check('豁免名单内用户 XML-RPC 登录成功', false === strpos($resp, '<fault>') && false !== strpos($resp, 'blogName'), substr($resp, 0, 120));

// 场景⑧: 豁免用户 XML-RPC 发评论 (需临时关闭核心反垃圾, 见 README 说明)
siteOption('commentsAntiSpam', '0');
$resp = xmlRpcCall('wp.newComment', [1, 'admin', 'Altcha#2026@Test', 1, [
    'comment_author' => 'XMLRPC机器人',
    'author'         => 'XMLRPC机器人',
    'content'        => 'XML-RPC 豁免场景测试评论 ' . time(),
]]);
check('豁免用户 XML-RPC 评论不被拦截', false === strpos($resp, '<fault>') && false !== strpos($resp, '<int>'), substr($resp, 0, 120));
siteOption('commentsAntiSpam', $antiSpamBackup);

// 场景⑨: 名单清空 → 豁免失效, XML-RPC 恢复拦截
setPluginConfig(['xmlrpcUsers' => '']);
$resp = xmlRpcCall('blogger.getUsersBlogs', [1, 'admin', 'Altcha#2026@Test']);
check('名单清空后 XML-RPC 恢复拦截', false !== strpos($resp, '<fault>'), substr($resp, 0, 120));

// ===== 场景⑩: 网页评论伪造查询串不得触发 XML-RPC 豁免 (BUG-R2-C2-A1-H1) =====

/** 创建临时非管理员 (subscriber) 账号, 返回 [uid, 用户名, 密码] */
function createBotUser(): array
{
    global $db;
    $name = 'altcha_bot';
    $password = 'AltchaBot#2026Test';
    $db->query($db->delete('table.comments')->where('author = ?', $name));
    $db->query($db->delete('table.users')->where('name = ?', $name));
    $uid = (int) $db->query($db->insert('table.users')->rows([
        'name'       => $name,
        'password'   => (new \Utils\PasswordHash(8, true))->hashPassword($password),
        'mail'       => $name . '@example.com',
        'url'        => '',
        'screenName' => 'E2E Bot',
        'created'    => time(),
        'activated'  => time(),
        'logged'     => 0,
        'group'      => 'subscriber',
        'authCode'   => null,
    ]));

    return [$uid, $name, $password];
}

/** 以指定账号走网页登录 (带一次真实 PoW), 返回 [Cookie 头, 状态行, 响应体] */
function webLogin(string $formAction, string $pageUrl, string $name, string $password): array
{
    global $siteSecret, $baseUrl;
    $challenge = Challenge::fromArray(json_decode(file_get_contents($baseUrl . '/index.php/action/altcha'), true));
    $solution = (new Altcha($siteSecret, $siteSecret))->solveChallenge(new SolveChallengeOptions(
        challenge: $challenge,
        algorithm: Plugin::algorithm($challenge->parameters->algorithm),
        timeout: 60.0,
    ));
    $payload = (new Payload($challenge, $solution))->toBase64();

    $context = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Referer: {$pageUrl}\r\nContent-Type: application/x-www-form-urlencoded\r\nUser-Agent: AltchaE2E/1.0\r\n",
        'content' => http_build_query([
            'name'     => $name,
            'password' => $password,
            'referer'  => '',
            'altcha'   => $payload,
        ]),
        'ignore_errors'   => true,
        'follow_location' => 0,
    ]]);
    $body = (string) file_get_contents($formAction, false, $context);

    $cookies = [];
    foreach ($http_response_header ?? [] as $header) {
        if (0 === stripos($header, 'Set-Cookie:')) {
            $pair = explode(';', trim(substr($header, 11)), 2)[0];
            if (str_contains($pair, '=')) {
                [$cookieName, $cookieValue] = explode('=', $pair, 2);
                $cookies[trim($cookieName)] = trim($cookieValue);
            }
        }
    }

    $cookieHeader = [];
    foreach ($cookies as $cookieName => $cookieValue) {
        $cookieHeader[] = $cookieName . '=' . $cookieValue;
    }

    return [implode('; ', $cookieHeader), (string) ($http_response_header[0] ?? ''), $body];
}

[$botUid, $botName, $botPassword] = createBotUser();
[$loginPageUrl, $loginFormAction] = (function (): array {
    global $baseUrl;
    $pageUrl = $baseUrl . '/admin/login.php';
    $html = (string) file_get_contents($pageUrl);
    if (!preg_match('/action="([^"]+)"[^>]*name="login"/', $html, $matches)) {
        throw new RuntimeException('无法解析登录表单');
    }
    return [$pageUrl, html_entity_decode($matches[1], ENT_QUOTES)];
})();

setPluginConfig(['xmlrpcBypass' => 'enable', 'xmlrpcUsers' => $botName]);
[$botCookie, $loginStatus, $loginBody] = webLogin($loginFormAction, $loginPageUrl, $botName, $botPassword);
check('subscriber 账号网页登录成功 (拿到会话 Cookie)', '' !== $botCookie && 302 === (int) (preg_match('/\s(\d{3})\s/', $loginStatus, $m) ? $m[1] : 0), $loginStatus . ' ' . msgOf($loginBody));

$authRow = $db->fetchRow($db->select('authCode', 'uid')->from('table.users')->where('uid = ?', $botUid));
$commentToken = md5($antiSpamSecret . '&' . $authRow['authCode'] . '&' . $botUid . '&' . $referer);
$bypassUrl = $baseUrl . '/index.php/archives/1/comment?action=xmlrpc';
$bypassContext = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Referer: {$referer}\r\nCookie: {$botCookie}\r\nContent-Type: application/x-www-form-urlencoded\r\nUser-Agent: AltchaE2E/1.0\r\n",
    'content' => http_build_query([
        'author' => 'E2E Bot',
        'mail'   => $botName . '@example.com',
        'url'    => '',
        'text'   => '查询串伪造 XML-RPC 豁免测试 ' . time(),
        '_'      => $commentToken,
    ]),
    'ignore_errors'   => true,
    'follow_location' => 0,
]]);
cleanupTestComments();
$bypassBody = (string) file_get_contents($bypassUrl, false, $bypassContext);
preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $bypassStatus);
check('网页评论附带 ?action=xmlrpc 仍需人机验证 (403)', 403 === (int) ($bypassStatus[1] ?? 0), 'status=' . ($bypassStatus[1] ?? '?'));
check('错误消息为「请先完成人机验证」', false !== strpos($bypassBody, '请先完成人机验证'), msgOf($bypassBody));

// 清理临时账号与配置
$db->query($db->delete('table.comments')->where('authorId = ?', $botUid));
$db->query($db->delete('table.users')->where('uid = ?', $botUid));

// 还原插件配置与站点状态
setPluginConfig(['xmlrpcBypass' => 'disable', 'xmlrpcUsers' => '']);
cleanupTestComments();

echo $failures === 0 ? "\n全部通过\n" : "\n{$failures} 项失败\n";
exit($failures === 0 ? 0 : 1);
