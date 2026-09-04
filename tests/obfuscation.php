<?php
/**
 * 内容保护 (Obfuscation) CLI 测试
 * 用法: php tests/obfuscation.php
 */

require __DIR__ . '/../Altcha/autoload.php';

// Plugin.php 带 ROOT_DIR 守卫, CLI 下需模拟定义并显式加载
if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', '/');
}
// CLI 无 Typecho 环境, stub 插件接口
if (!interface_exists('Typecho\Plugin\PluginInterface')) {
    eval('namespace Typecho\Plugin; interface PluginInterface {}');
}
require_once __DIR__ . '/../Altcha/Plugin.php';

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Obfuscator;

$pluginClass = 'TypechoPlugin\Altcha\Plugin';

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . "  {$name}" . ($cond ? '' : "  [{$detail}]") . "\n";
    if (!$cond) {
        $failures++;
    }
}

// ─── 1. 库级往返: obfuscate → deobfuscate ───
$obfuscator = new Obfuscator(new Altcha(null), new Pbkdf2());

$cases = [
    'admin@example.com',
    'mailto:hello@example.com',
    '电话 138-0013-8000, 微信 wx_2000 & QQ:12345',
    "多行文本\n第二行",
];
foreach ($cases as $i => $plaintext) {
    $payload = $obfuscator->obfuscate($plaintext);
    $roundtrip = $obfuscator->deobfuscate($payload);
    check("库级往返 #{$i}", $roundtrip === $plaintext, "got: {$roundtrip}");
}

// 往返 payload 不含明文
$payload = $obfuscator->obfuscate('SECRET-EMAIL@example.com');
check('密文不含明文', false === strpos($payload, 'SECRET-EMAIL'));

// ─── 2. 插件标记解析 (CLI 下 configValue 走默认值: protectEnable=enable) ───
$html = '<p>联系我: <altcha-protect>admin@example.com</altcha-protect> 或电话 <altcha-protect label="查看电话">13800138000</altcha-protect></p>';
$out = $pluginClass::filterContent($html);

check('标记被替换为组件', 2 === substr_count($out, '<altcha-widget data-obfuscated='));
check('输出不含明文邮箱', false === strpos($out, 'admin@example.com'));
check('输出不含明文电话', false === strpos($out, '13800138000'));
check('输出不含原始标记', false === stripos($out, '<altcha-protect'));
check('label 属性生效', false !== strpos($out, '>查看电话</button>'));
check('默认按钮文案生效', false !== strpos($out, '>验证后查看</button>'));
check('floating 展示模式', 2 === substr_count($out, 'display="floating"'));

// 多行/带实体的内容
$html2 = "<altcha-protect>多词 &amp; 符号\n带换行的秘密</altcha-protect>";
$out2 = $pluginClass::filterContent($html2);
preg_match('/data-obfuscated="([^"]+)"/', $out2, $m);
check('实体解码后加密', isset($m[1]) && false === strpos($m[1], '&amp;'));
$decoded = $obfuscator->deobfuscate($m[1] ?? '');
check('解密文本为规范化单行', '多词 & 符号 带换行的秘密' === $decoded, "got: {$decoded}");

// 无标记内容原样返回
$plain = '<p>正常内容, 无标记</p>';
check('无标记内容原样返回', $plain === $pluginClass::filterContent($plain));

// RSS 上下文分支
$ref = new ReflectionClass($pluginClass);
$pluginClass::markFeedContext();
$outFeed = $pluginClass::filterContent($html);
check('feed 中无组件', false === stripos($outFeed, '<altcha-widget'));
check('feed 中无明文', false === strpos($outFeed, 'admin@example.com'));
check('feed 中为占位文案', 2 === substr_count($outFeed, '[受保护内容]'));

// 复位 feed 标志
$feedProp = $ref->getProperty('feedContext');
$feedProp->setValue(null, false);

// ─── 4. 摘要过滤: 加密组件被替换为占位文案 ───
$contentWithWidget = '<p>前面部分</p>' . $out . '<p>后面部分</p>';
$excerpt = $pluginClass::filterExcerpt($contentWithWidget);
check('摘要中组件被替换', 0 === substr_count($excerpt, 'data-obfuscated'));
check('摘要中为占位文案', 2 === substr_count($excerpt, '[受保护内容]'));
check('摘要保留其余内容', false !== strpos($excerpt, '前面部分') && false !== strpos($excerpt, '后面部分'));

// 幂等: 二次过滤不再变化
check('摘要过滤幂等', $excerpt === $pluginClass::filterExcerpt($excerpt));

// 评论表单组件 (challenge 属性) 不受摘要过滤影响
$commentWidget = '<form><altcha-widget challenge="http://x/action/altcha" name="altcha"></altcha-widget></form>';
check('评论验证组件不受影响', $commentWidget === $pluginClass::filterExcerpt($commentWidget));

// ─── 5. 已用页面标志 ───
$usedProp = $ref->getProperty('usedOnPage');
check('常规替换置页面标志', true === $usedProp->getValue());

echo $failures === 0 ? "\n全部通过\n" : "\n{$failures} 项失败\n";
exit($failures === 0 ? 0 : 1);
