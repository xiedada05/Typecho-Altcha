<?php
/**
 * 下载前端组件构建产物到 Altcha/assets/ (产物不入库, 发布 zip 与本地开发共用)
 *
 * 用法:
 *   php scripts/fetch-assets.php
 *   php scripts/fetch-assets.php --cdn-base=https://registry.npmmirror.com/altcha/3.2.2/files/dist
 *   php scripts/fetch-assets.php --dest=/some/dir
 *
 * 版本单一来源: Altcha/Plugin.php 的 WIDGET_VERSION 常量。
 * Windows: php scripts\fetch-assets.php
 */

$root = dirname(__DIR__);
$pluginPhp = @file_get_contents($root . '/Altcha/Plugin.php');

if (false === $pluginPhp || !preg_match("/const WIDGET_VERSION = '([0-9.]+)'/", $pluginPhp, $versionMatch)) {
    fwrite(STDERR, "错误: 未能从 Altcha/Plugin.php 解析出 WIDGET_VERSION\n");
    exit(1);
}
$version = $versionMatch[1];

$dest = $root . '/Altcha/assets';
$cdnBase = null;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--cdn-base=(.+)$/', $arg, $m)) {
        $cdnBase = rtrim($m[1], '/');
    } elseif (preg_match('/^--dest=(.+)$/', $arg, $m)) {
        $dest = rtrim($m[1], '/');
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n用法: php scripts/fetch-assets.php [--cdn-base=URL] [--dest=DIR]\n");
        exit(1);
    }
}

$cdnBase = $cdnBase ?: "https://cdn.jsdelivr.net/npm/altcha@{$version}/dist";

$files = [
    '/main/altcha.min.js'                 => 'altcha.min.js',
    '/main/altcha.i18n.min.js'            => 'altcha.i18n.min.js',
    '/plugins/obfuscation.plugin.min.js'  => 'obfuscation.plugin.min.js',
];

if (!is_dir($dest) && !mkdir($dest, 0777, true)) {
    fwrite(STDERR, "错误: 无法创建目录 {$dest}\n");
    exit(1);
}

$context = stream_context_create(['http' => ['header' => "User-Agent: typecho-altcha-fetch/1.0\r\n"]]);

foreach ($files as $path => $name) {
    $url = $cdnBase . $path;
    echo "下载 {$name} (altcha@{$version}) ...\n";
    $data = @file_get_contents($url, false, $context);
    if (false === $data) {
        fwrite(STDERR, "错误: 下载失败 {$url}\n可尝试 --cdn-base= 指定其他镜像\n");
        exit(1);
    }
    file_put_contents($dest . '/' . $name, $data);
}

echo "完成: {$dest}\n";
