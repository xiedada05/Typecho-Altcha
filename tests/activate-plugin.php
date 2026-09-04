<?php
/**
 * CLI 启用/禁用 Altcha 插件并写入默认配置
 * (等价于后台的「启用插件 + 保存设置」, 免浏览器与 CSRF, 适配器无关)
 *
 * 用法:
 *   php tests/activate-plugin.php <config.inc.php路径> [activate|deactivate]
 */

array_shift($argv);
$configFile = $argv[0] ?? dirname(__DIR__) . '/../typecho/config.inc.php';
$action = $argv[1] ?? 'activate';

if (!is_file($configFile)) {
    fwrite(STDERR, "站点配置文件不存在: {$configFile}\n");
    exit(2);
}

require $configFile;

$options = \Widget\Options::alloc();
// 导入已持久化的插件状态 (等价 Widget\Init 的 Plugin::init)
\Typecho\Plugin::init($options->plugins ?: []);

[$pluginFile, $className] = \Typecho\Plugin::portal('Altcha', $options->pluginDir);
require_once $pluginFile;

$db = \Typecho\Db::get();

if ('deactivate' === $action) {
    call_user_func([$className, 'deactivate']);
    \Typecho\Plugin::deactivate('Altcha');
    $db->query($db->update('table.options')
        ->rows(['value' => json_encode(\Typecho\Plugin::export())])
        ->where('name = ?', 'plugins'));
    echo "Altcha 插件已禁用 (防重放表应已删除)\n";
    exit(0);
}

call_user_func([$className, 'activate']);
\Typecho\Plugin::activate('Altcha');

// 持久化插件状态 (等价 Widget\Plugins\Edit::activate 的落库步骤)
$db->query($db->update('table.options')
    ->rows(['value' => json_encode(\Typecho\Plugin::export())])
    ->where('name = ?', 'plugins'));

// 写入插件配置: 评论 + 登录双保护
\Widget\Plugins\Edit::configPlugin('Altcha', [
    'hmacSecret'    => bin2hex(random_bytes(32)),
    'enableActions' => ['comment', 'login'],
    'difficulty'    => 'middle',
    'expire'        => 15,
    'theme'         => 'auto',
    'language'      => 'auto',
    'scriptSource'  => 'local',
    'autoInject'    => 'enable',
]);

echo "Altcha 插件已启用并写入配置 (适配器: ", $db->getAdapterName(), ")\n";
exit(0);
