<?php
/**
 * ALTCHA PHP 库自动加载器
 *
 * Altcha/lib 是指向 https://github.com/altcha-org/altcha-lib-php 的 git submodule,
 * 库源码位于 lib/src/ (PSR-4: AltchaOrg\Altcha\), 零第三方依赖, 无需 Composer。
 *
 * 库源码缺失 (未以 --recursive 克隆或未初始化 submodule) 时写 error_log 提示。
 */

if (!\class_exists('AltchaOrg\\Altcha\\Autoloader', false)) {
    \spl_autoload_register(function (string $class): void {
        static $reported = false;

        if (!\str_starts_with($class, 'AltchaOrg\\Altcha\\')) {
            return;
        }

        $relative = \substr($class, \strlen('AltchaOrg\\Altcha\\'));
        $file = __DIR__ . '/lib/src/' . \str_replace('\\', '/', $relative) . '.php';

        if (\is_file($file)) {
            require $file;
            return;
        }

        if (!$reported) {
            $reported = true;
            if (!\is_dir(__DIR__ . '/lib/src')) {
                \error_log(
                    'Typecho Altcha plugin: ALTCHA 库源码缺失, 请执行'
                    . ' "git submodule update --init --recursive" 或从 Release 页下载完整包'
                );
            }
        }
    });
}
