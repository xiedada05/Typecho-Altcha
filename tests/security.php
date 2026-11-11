<?php
/**
 * 安全回归测试 (无需外部 HTTP 服务)
 *
 * 覆盖:
 * - 防重放表存储异常时必须 fail-closed (BUG-R2-C2-A2-H1)
 * - 重复键仍应被识别为「重放」而非存储故障
 *
 * 用法: php tests/security.php [config.inc.php路径]
 * 默认使用 ../typecho/config.inc.php
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
require_once __DIR__ . '/fixtures/AltchaBusyAdapter.php';

use Typecho\Db;
use TypechoPlugin\Altcha\Plugin;

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . "  {$name}" . ($cond ? '' : "  [{$detail}]") . "\n";
    if (!$cond) {
        $failures++;
    }
}

$db = Db::get();
$dbFile = (string) ($db->getConfig(Db::WRITE)->file ?? '');
if ('' === $dbFile || !is_file($dbFile)) {
    echo "SKIP  该用例通过模拟文件型 SQLite 适配器验证 fail-closed, 需要 Pdo_SQLite 测试站点\n";
    exit(0);
}

$consume = new ReflectionMethod(Plugin::class, 'consumePayload');
$consume->setAccessible(true);

/** 在模拟适配器下调用一次消费 */
function consumeWithAdapter(ReflectionMethod $consume, string $mode, string $signature): ?string
{
    \Typecho\Db\Adapter\AltchaBusyAdapter::$mode = $mode;
    $result = $consume->invoke(null, $signature, 60);

    return null === $result ? null : (string) $result;
}

$originalDb = $db;
$mockDb = new Db('AltchaBusyAdapter', $originalDb->getPrefix());
$mockDb->addServer(['file' => $dbFile], Db::READ | Db::WRITE);
Db::set($mockDb);

try {
    $result = consumeWithAdapter($consume, 'busy', 'security-test-' . bin2hex(random_bytes(16)));
    check('SQLITE_BUSY 存储异常时 fail-closed (返回拒绝而非放行)', null !== $result, var_export($result, true));
    check(
        'fail-closed 消息为「验证服务暂时不可用」',
        null !== $result && false !== strpos($result, '验证服务暂时不可用'),
        (string) $result
    );

    $result = consumeWithAdapter($consume, 'duplicate', 'security-test-' . bin2hex(random_bytes(16)));
    check('重复键仍被识别为重放 (403 语义)', null !== $result && false !== strpos($result, '已被使用'), (string) $result);
} finally {
    Db::set($originalDb);
}

echo $failures === 0 ? "\n全部通过\n" : "\n{$failures} 项失败\n";
exit($failures === 0 ? 0 : 1);
