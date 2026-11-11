<?php
/**
 * CLI 闭环测试: 模拟 挑战签发 → 客户端解题 → 表单提交 → 服务端验证 全流程
 * 覆盖全部可选哈希算法 (Plugin::supportedAlgorithms) 与难度滑块映射
 * 用法: php tests/closed-loop.php
 */

require __DIR__ . '/../Altcha/autoload.php';

if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', dirname(__DIR__) . '/../typecho/');
}

// 轻量 PSR-4 加载 Typecho 类 (仅类定义, 不启动框架), 供 Plugin.php 可被 require
\spl_autoload_register(function (string $class): void {
    foreach (['Typecho', 'Widget', 'Utils'] as $ns) {
        if (\str_starts_with($class, $ns . '\\')) {
            $file = __TYPECHO_ROOT_DIR__ . 'var/' . \str_replace('\\', '/', $class) . '.php';
            if (\is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require_once __DIR__ . '/../Altcha/Plugin.php';

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;
use TypechoPlugin\Altcha\Plugin;

$secret = 'unit-test-secret-0123456789abcdef';
$altcha = new Altcha($secret, $secret);
$pbkdf2 = new Pbkdf2();

$failures = 0;

function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
    if (!$cond) {
        $failures++;
    }
}

// 0. 难度滑块映射: 数值直用, 旧版字符串预设兼容, 越界钳制
foreach ([
    2000 => 2000, '2000' => 2000, '800' => 800,
    'low' => 800, 'middle' => 2000, 'high' => 10000,
    100 => 500, 99999 => 20000, null => 2000, 'garbage' => 2000,
] as $input => $cost) {
    [$actual] = Plugin::difficultyParams($input);
    check("难度映射 " . var_export($input, true) . " => {$cost}", $actual === $cost);
}

// 0.5 算法名 → 实例, 名称与库内 getAlgorithmName 一致
foreach (Plugin::supportedAlgorithms() as $name) {
    check("算法映射 {$name}", Plugin::algorithm($name)->getAlgorithmName() === $name);
}
check('未知算法回退 PBKDF2/SHA-256', Plugin::algorithm('no-such-algo') instanceof Pbkdf2);

foreach (Plugin::supportedAlgorithms() as $algoIndex => $algoName) {
    $algorithm = Plugin::algorithm($algoName);
    // 1. Action::action() 签发 (与 Action.php 参数一致: 不传 counter, 由库生成随机 keyPrefix)
    $cost = 0 === $algoIndex % 2 ? 800 : 2000;
    $challenge = $altcha->createChallenge(new CreateChallengeOptions(
        algorithm: $algorithm,
        cost: $cost,
        keyPrefix: '',
        keyPrefixLength: 1,
        expiresAt: time() + 900,
    ));
    check("[{$algoName}] 挑战声明算法正确", $challenge->parameters->algorithm === $algoName);
    check("[{$algoName}] 签发不携带 keySignature (快路径未启用)", null === $challenge->parameters->keySignature);
    check(
        "[{$algoName}] keyPrefix 为随机 1 字节",
        1 === preg_match('/^[0-9a-f]{2}$/', $challenge->parameters->keyPrefix)
    );

    // 2. 浏览器端解题
    $start = microtime(true);
    $solution = $altcha->solveChallenge(new SolveChallengeOptions(
        challenge: $challenge,
        algorithm: $algorithm,
        timeout: 120.0,
    ));
    $took = microtime(true) - $start;
    check("[{$algoName}] 挑战可解 (耗时 " . round($took, 3) . "s)", null !== $solution);

    // 3. 表单提交 base64 payload
    $payload = (new Payload($challenge, $solution))->toBase64();

    // 4. Plugin::checkPayload 服务端验证 (按挑战声明算法, 即后台切换算法也不影响)
    $result = $altcha->verifySolution(new VerifySolutionOptions(
        payload: $payload,
        algorithm: Plugin::algorithm($challenge->parameters->algorithm),
    ));
    check("[{$algoName}] 合法 payload 验证通过", $result->verified);

    // 5. 篡改 challenge 签名
    $tampered = json_decode(base64_decode($payload), true);
    $tampered['challenge']['signature'] = str_repeat('0', 64);
    $result = $altcha->verifySolution(new VerifySolutionOptions(
        payload: base64_encode(json_encode($tampered)),
        algorithm: $algorithm,
    ));
    check("[{$algoName}] 篡改签名被拒绝", !$result->verified && $result->invalidSignature);

    // 6. 篡改解答 (counter 换成别的值)
    $tampered = json_decode(base64_decode($payload), true);
    $tampered['solution']['counter'] = 987654321;
    $result = $altcha->verifySolution(new VerifySolutionOptions(
        payload: base64_encode(json_encode($tampered)),
        algorithm: $algorithm,
    ));
    check("[{$algoName}] 篡改 counter 被拒绝", !$result->verified);

    // 7. 非法 base64 → InvalidArgumentException
    $threw = false;
    try {
        new VerifySolutionOptions(payload: '###not-base64###', algorithm: $algorithm);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    check("[{$algoName}] 非法 base64 抛出异常", $threw);
}

// 8. 过期挑战
$challenge = $altcha->createChallenge(new CreateChallengeOptions(
    algorithm: $pbkdf2,
    cost: 100,
    keyPrefixLength: 1,
    counter: 5,
    expiresAt: time() - 10,
));
$solution = $altcha->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: $pbkdf2));
$result = $altcha->verifySolution(new VerifySolutionOptions(
    payload: (new Payload($challenge, $solution))->toBase64(),
    algorithm: $pbkdf2,
));
check("过期挑战被拒绝", !$result->verified && $result->expired);

// 9. 换密钥后旧 payload 失效 (模拟后台修改 HMAC 密钥)
$oldPayload = (new Payload(
    $altcha->createChallenge(new CreateChallengeOptions(
        algorithm: $pbkdf2,
        cost: 100,
        keyPrefixLength: 1,
        counter: 5,
    )),
    $altcha->solveChallenge(new SolveChallengeOptions(
        challenge: $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $pbkdf2,
            cost: 100,
            keyPrefixLength: 1,
            counter: 5,
        )),
        algorithm: $pbkdf2,
    )),
))->toBase64();
$newAltcha = new Altcha('rotated-secret-9876543210abcdef', 'rotated-secret-9876543210abcdef');
$result = $newAltcha->verifySolution(new VerifySolutionOptions(payload: $oldPayload, algorithm: $pbkdf2));
check("密钥更换后旧 payload 失效", !$result->verified);

echo $failures === 0 ? "\n全部通过\n" : "\n{$failures} 项失败\n";
exit($failures === 0 ? 0 : 1);
