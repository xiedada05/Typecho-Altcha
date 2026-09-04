<?php
/**
 * CLI 闭环测试: 模拟 挑战签发 → 客户端解题 → 表单提交 → 服务端验证 全流程
 * 用法: php tests/closed-loop.php
 */

require __DIR__ . '/../Altcha/autoload.php';

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;

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

foreach (['low' => [800, 1], 'middle' => [2000, 1], 'high' => [10000, 1]] as $preset => [$cost, $prefixLen]) {
    // 1. Action::action() 签发 (与 Action.php 参数一致)
    $challenge = $altcha->createChallenge(new AltchaOrg\Altcha\CreateChallengeOptions(
        algorithm: $pbkdf2,
        cost: $cost,
        keyPrefixLength: $prefixLen,
        counter: random_int(500, 15000),
        expiresAt: time() + 900,
    ));

    // 2. 浏览器端解题
    $start = microtime(true);
    $solution = $altcha->solveChallenge(new SolveChallengeOptions(
        challenge: $challenge,
        algorithm: $pbkdf2,
        timeout: 120.0,
    ));
    $took = microtime(true) - $start;
    check("[{$preset}] 挑战可解 (耗时 " . round($took, 3) . "s)", null !== $solution);

    // 3. 表单提交 base64 payload
    $payload = (new Payload($challenge, $solution))->toBase64();

    // 4. Plugin::checkPayload 服务端验证
    $result = $altcha->verifySolution(new VerifySolutionOptions(payload: $payload, algorithm: $pbkdf2));
    check("[{$preset}] 合法 payload 验证通过", $result->verified);

    // 5. 篡改 challenge 签名
    $tampered = json_decode(base64_decode($payload), true);
    $tampered['challenge']['signature'] = str_repeat('0', 64);
    $result = $altcha->verifySolution(new VerifySolutionOptions(
        payload: base64_encode(json_encode($tampered)),
        algorithm: $pbkdf2,
    ));
    check("[{$preset}] 篡改签名被拒绝", !$result->verified && $result->invalidSignature);

    // 6. 篡改解答 (counter 换成别的值)
    $tampered = json_decode(base64_decode($payload), true);
    $tampered['solution']['counter'] = 987654321;
    $result = $altcha->verifySolution(new VerifySolutionOptions(
        payload: base64_encode(json_encode($tampered)),
        algorithm: $pbkdf2,
    ));
    check("[{$preset}] 篡改 counter 被拒绝", !$result->verified);

    // 7. 非法 base64 → InvalidArgumentException
    $threw = false;
    try {
        new VerifySolutionOptions(payload: '###not-base64###', algorithm: $pbkdf2);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    check("[{$preset}] 非法 base64 抛出异常", $threw);
}

// 8. 过期挑战
$challenge = $altcha->createChallenge(new AltchaOrg\Altcha\CreateChallengeOptions(
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
    $altcha->createChallenge(new AltchaOrg\Altcha\CreateChallengeOptions(
        algorithm: $pbkdf2,
        cost: 100,
        keyPrefixLength: 1,
        counter: 5,
    )),
    $altcha->solveChallenge(new SolveChallengeOptions(
        challenge: $altcha->createChallenge(new AltchaOrg\Altcha\CreateChallengeOptions(
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
