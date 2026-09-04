<?php
/**
 * ALTCHA 人机验证插件
 *
 * 由 Typecho-Turnstile 插件爆改而来, 验证后端替换为 ALTCHA
 * 工作量证明 (Proof-of-Work), 无外部服务依赖, 验证过程完全本地完成。
 *
 * @package Altcha
 * @author xiedada05
 * @version 2.0.0
 * @link https://github.com/xiedada05/Typecho-Altcha
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

namespace TypechoPlugin\Altcha;

require_once __DIR__ . '/autoload.php';

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Obfuscator;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Typecho\Common;
use Typecho\Config;
use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Number;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\PasswordHash;
use Widget\Notice;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * ALTCHA 人机验证插件
 *
 * @package Altcha
 */
class Plugin implements PluginInterface
{
    public const PLUGIN_NAME = 'Altcha';

    /**
     * 是否启用救援模式
     * 启用后, 将跳过登录验证, 适用于无法通过验证时临时排查问题
     */
    private const RESCUE_MODE = false;

    /**
     * CDN 备选脚本 (固定版本, 与本地打包版本一致)
     */
    private const CDN_BASE = 'https://cdn.jsdelivr.net/npm/altcha@3.2.2/dist/main/';

    /**
     * 混淆插件的 CDN 备选地址
     */
    private const CDN_PLUGINS_BASE = 'https://cdn.jsdelivr.net/npm/altcha@3.2.2/dist/plugins/';

    /**
     * 当前页面是否输出过受保护内容 (决定是否加载混淆脚本)
     */
    private static bool $usedOnPage = false;

    /**
     * 当前是否处于 RSS/Atom 输出上下文 (feedItem 钩子先于内容渲染触发)
     */
    private static bool $feedContext = false;

    /**
     * 组件主脚本是否已在本页输出 (避免重复加载)
     */
    private static bool $widgetScriptPrinted = false;

    public static function activate()
    {
        if (PHP_VERSION_ID < 80100) {
            throw new \Typecho\Plugin\Exception(_t('ALTCHA 插件需要 PHP 8.1 及以上版本, 当前版本: ' . PHP_VERSION));
        }

        // 防重放数据表, 建表失败则中止激活, 不残留任何状态
        try {
            self::createReplayTable();
        } catch (\Exception $e) {
            throw new \Typecho\Plugin\Exception(_t('创建防重放数据表失败: ' . $e->getMessage()));
        }

        \Typecho\Plugin::factory('Widget\Feedback')->comment = [__CLASS__, 'verifyComment'];
        \Typecho\Plugin::factory('Widget\Archive')->footer = [__CLASS__, 'renderFooter'];
        \Typecho\Plugin::factory('admin/footer.php')->end = [__CLASS__, 'renderLogin'];
        \Typecho\Plugin::factory('Widget\User')->hashValidate = [__CLASS__, 'verifyLogin'];

        // 内容保护: 正文/摘要/RSS 过滤
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = [__CLASS__, 'filterContent'];
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerpt = [__CLASS__, 'filterExcerpt'];
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx = [__CLASS__, 'filterExcerpt'];
        \Typecho\Plugin::factory('Widget\Feed')->feedItem = [__CLASS__, 'markFeedContext'];

        \Utils\Helper::addAction('altcha', Action::class);

        return _t('ALTCHA 插件已启用, 请在插件设置中确认需要保护的场景');
    }

    public static function deactivate()
    {
        \Utils\Helper::removeAction('altcha');
        self::dropReplayTable();
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function config(Form $form)
    {
        $secret = new Text(
            'hmacSecret',
            null,
            self::generateSecret(),
            _t('HMAC 密钥'),
            _t('用于签发和校验挑战, 启用插件时已自动生成。修改后所有已下发的挑战将立即失效。')
        );

        $enableActions = new Checkbox('enableActions', [
            'comment' => _t('评论'),
            'login'   => _t('登录'),
        ], ['comment'], _t('在哪些地方启用验证'), _t('启用评论验证后无需修改主题模板, 组件会自动插入评论表单'));

        $difficulty = new Radio('difficulty', [
            'low'    => _t('低'),
            'middle' => _t('中'),
            'high'   => _t('高'),
        ], 'middle', _t('验证难度'), _t('越高对机器人越昂贵, 对访客设备的要求也越高'));

        $expire = new Number('expire', null, 15, _t('挑战有效期 (分钟)'), _t('超过时限的挑战将被判定为过期'));

        $theme = new Radio('theme', [
            'auto'  => _t('自动'),
            'light' => _t('亮色'),
            'dark'  => _t('暗色'),
        ], 'auto', _t('外观主题'), null);

        $language = new Radio('language', [
            'auto' => _t('跟随浏览器'),
            'zh'   => _t('简体中文'),
            'en'   => _t('English'),
        ], 'auto', _t('组件语言'), null);

        $scriptSource = new Radio('scriptSource', [
            'local' => _t('本地打包'),
            'cdn'   => _t('CDN'),
        ], 'local', _t('组件脚本来源'), _t('本地打包时验证流程不产生任何第三方网络请求'));

        $autoInject = new Radio('autoInject', [
            'enable'  => _t('启用'),
            'disable' => _t('禁用'),
        ], 'enable', _t('自动插入评论验证组件'), _t('禁用后需要在主题 comments.php 的评论表单内自行调用 &lt;?php \TypechoPlugin\Altcha\Plugin::output(); ?&gt;'));

        $protectEnable = new Radio('protectEnable', [
            'enable'  => _t('启用'),
            'disable' => _t('禁用'),
        ], 'enable', _t('内容保护 (防爬取)'), _t('自动加密正文里 &lt;altcha-protect&gt; 标记的内容, 访客点击按钮验证后才能查看, 爬虫只能抓到密文。需要 PHP openssl 扩展, 缺失时标记内容将明文显示。Markdown 与 HTML 编辑模式下写法相同, 在文章中插入: &lt;altcha-protect&gt;mailto:admin@example.com&lt;/altcha-protect&gt; , 以 mailto: / tel: / sms: / https?: 开头的内容在揭示后会自动变为可点击链接; 可用 label 属性自定义按钮文案, 如 &lt;altcha-protect label="查看电话"&gt;138-0013-8000&lt;/altcha-protect&gt;。列表摘要与 RSS 中显示为占位文案, 不泄露明文与密文。注意: 保护块内请勿留空行, 不支持嵌套。'));

        $protectLabel = new Text('protectLabel', null, '验证后查看',
            _t('保护内容按钮文案'), _t('访客点击它来揭示被保护的内容, 可被标记上的 label 属性覆盖'));

        $protectPlaceholder = new Text('protectPlaceholder', null, '[受保护内容]',
            _t('摘要占位文案'), _t('列表摘要与 RSS 订阅中, 受保护内容显示为此占位文本'));

        $form->addInput($secret);
        $form->addInput($enableActions);
        $form->addInput($difficulty);
        $form->addInput($expire);
        $form->addInput($theme);
        $form->addInput($language);
        $form->addInput($scriptSource);
        $form->addInput($autoInject);
        $form->addInput($protectEnable);
        $form->addInput($protectLabel);
        $form->addInput($protectPlaceholder);
    }

    /**
     * 供主题手动插入验证组件
     * 与自动注入互斥: 表单内已存在组件时自动注入会跳过
     */
    public static function output()
    {
        if (!self::enabled('comment')) {
            return;
        }

        echo '<altcha-widget'
            . ' challenge="' . htmlspecialchars(self::challengeUrl()) . '"'
            . ' name="altcha"'
            . self::widgetAttributes()
            . '></altcha-widget>';
    }

    /**
     * 评论验证钩子: Widget\Feedback->comment
     */
    public static function verifyComment(array $comment, $content, array $original): array
    {
        if (!self::enabled('comment') || self::skipForAdmin()) {
            return $comment;
        }

        $error = self::checkPayload($_POST['altcha'] ?? '');
        if (null !== $error) {
            throw new \Typecho\Widget\Exception(_t($error), 403);
        }

        return $comment;
    }

    /**
     * 前台页脚钩子: Widget\Archive->footer
     * 向评论表单自动插入验证组件
     */
    public static function renderFooter()
    {
        // 本页含受保护内容: 先加载组件主脚本再加载混淆插件 (同为 module, 按文档顺序执行)
        if (self::$usedOnPage) {
            self::printScript();
            $pluginSrc = htmlspecialchars(self::obfuscationScriptUrl());
            echo '<script type="module" src="' . $pluginSrc . '"></script>';
        }

        if (!self::enabled('comment') || !self::autoInject()) {
            return;
        }

        self::printScript();
        echo '<script data-altcha-inject>
(function () {
    function inject() {
        if (document.querySelector("form altcha-widget")) {
            return;
        }
        var form = document.querySelector("#respond form") ||
            document.querySelector("form[action$=\\"/comment\\"]") ||
            document.querySelector("form[action*=\\"comment\\"]");
        if (!form) {
            return;
        }
        var widget = document.createElement("altcha-widget");
        widget.setAttribute("challenge", ' . json_encode(self::challengeUrl()) . ');
        widget.setAttribute("name", "altcha");
        ' . self::widgetAttributesJs() . '
        var submit = form.querySelector("button[type=submit], input[type=submit]");
        if (submit && submit.parentNode && submit.parentNode.parentNode === form) {
            form.insertBefore(widget, submit.parentNode);
        } else if (submit) {
            form.insertBefore(widget, submit);
        } else {
            form.appendChild(widget);
        }
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", inject);
    } else {
        inject();
    }
})();
</script>';
    }

    /**
     * 后台页脚钩子: admin/footer.php->end
     * 向登录表单插入验证组件
     */
    public static function renderLogin()
    {
        if (self::RESCUE_MODE || !self::enabled('login')) {
            return;
        }

        $requestUrl = Options::alloc()->request->getRequestUrl();
        if (false === stripos($requestUrl, 'login.php')) {
            return;
        }

        self::printScript();
        echo '<script data-altcha-inject>
(function () {
    function inject() {
        if (document.querySelector("form altcha-widget")) {
            return;
        }
        var form = document.forms.login || document.querySelector("form[action*=login]");
        if (!form) {
            return;
        }
        var widget = document.createElement("altcha-widget");
        widget.setAttribute("challenge", ' . json_encode(self::challengeUrl()) . ');
        widget.setAttribute("name", "altcha");
        ' . self::widgetAttributesJs() . '
        var password = form.querySelector("#password");
        var anchor = password ? password.closest("p") : form.querySelector("p.submit");
        if (anchor && anchor.parentNode === form) {
            form.insertBefore(widget, anchor.nextSibling);
        } else {
            form.appendChild(widget);
        }
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", inject);
    } else {
        inject();
    }
})();
</script>';
    }

    /**
     * 登录验证钩子: Widget\User->hashValidate
     * 先校验 ALTCHA, 通过后重放原生密码校验逻辑
     */
    public static function verifyLogin($password, $hash)
    {
        $validate = function () use ($password, $hash): bool {
            // 参考 /var/Widget/User.php 中的 login 方法
            if ('$P$' == substr($hash, 0, 3)) {
                $hasher = new PasswordHash(8, true);
                return $hasher->checkPassword($password, $hash);
            }
            return Common::hashValidate($password, $hash);
        };

        if (self::RESCUE_MODE || !self::enabled('login')) {
            return $validate();
        }

        $error = self::checkPayload($_POST['altcha'] ?? '');
        if (null !== $error) {
            self::loginFailed($error);
            return false;
        }

        return $validate();
    }

    /**
     * 正文输出过滤钩子: Widget\Base\Contents->contentEx
     * 将 <altcha-protect> 标记块替换为加密组件 (RSS 上下文替换为占位文案)
     */
    public static function filterContent($html, $widget = null, $ignored = null)
    {
        if (!\is_string($html) || '' === $html || false === stripos($html, '<altcha-protect')) {
            return $html;
        }

        // 优雅降级: 未启用或缺 openssl 时标记原样输出, 内容明文可见不丢失
        if (!self::protectionEnabled() || !self::opensslAvailable()) {
            return $html;
        }

        return self::replaceMarkers($html, function (string $text, string $label): string {
            return self::$feedContext
                ? self::placeholderText()
                : self::buildProtectWidget($text, $label);
        });
    }

    /**
     * 摘要过滤钩子: Widget\Base\Contents->excerpt / excerptEx
     * 列表摘要与 RSS description 会 strip_tags 输出, 必须把加密组件换成占位文案
     */
    public static function filterExcerpt($html, $widget = null, $ignored = null)
    {
        if (!\is_string($html) || '' === $html || false === stripos($html, '<altcha-widget')) {
            return $html;
        }

        return self::stripProtectWidgets($html);
    }

    /**
     * RSS 条目钩子: Widget\Feed->feedItem
     * 在内容渲染前标记 feed 上下文, 使 filterContent 直接输出占位文案
     */
    public static function markFeedContext($feedType = null, $archive = null)
    {
        self::$feedContext = true;
        return null;
    }

    private static function protectionEnabled(): bool
    {
        return self::configValue('protectEnable', 'enable') === 'enable';
    }

    private static function opensslAvailable(): bool
    {
        return \extension_loaded('openssl') && \function_exists('openssl_encrypt');
    }

    private static function placeholderText(): string
    {
        return htmlspecialchars((string) self::configValue('protectPlaceholder', '[受保护内容]'));
    }

    /**
     * 替换全部 <altcha-protect> 标记块
     *
     * @param callable(string, string): string $make 接收 (纯文本, label), 返回替换 HTML
     */
    private static function replaceMarkers(string $html, callable $make): string
    {
        $result = preg_replace_callback(
            '/<altcha-protect\b([^>]*)>(.*?)<\/altcha-protect>/is',
            function (array $matches) use ($make): string {
                $label = '';
                if (preg_match('/label\s*=\s*(["\'])(.*?)\1/is', $matches[1], $labelMatches)) {
                    $label = html_entity_decode($labelMatches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                return $make(self::extractProtectedText($matches[2]), $label);
            },
            $html
        );

        return \is_string($result) ? $result : $html;
    }

    /**
     * 标记块内部统一按纯文本处理 (浏览器端以文本节点插入, 仅 mailto:/tel:/sms:/https?: 前缀会转为链接)
     */
    private static function extractProtectedText(string $innerHtml): string
    {
        $text = strip_tags($innerHtml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function buildProtectWidget(string $text, string $label): string
    {
        $payload = self::obfuscator()->obfuscate($text);
        self::$usedOnPage = true;

        $buttonLabel = '' !== $label
            ? $label
            : (string) self::configValue('protectLabel', '验证后查看');

        return '<altcha-widget data-obfuscated="' . $payload . '" display="floating"'
            . self::widgetAttributes()
            . '><button type="button">' . htmlspecialchars($buttonLabel) . '</button></altcha-widget>';
    }

    private static function obfuscator(): Obfuscator
    {
        static $obfuscator = null;

        return $obfuscator ??= new Obfuscator(new Altcha(null), new Pbkdf2());
    }

    private static function stripProtectWidgets(string $html): string
    {
        $result = preg_replace(
            '/<altcha-widget\b[^>]*data-obfuscated[^>]*>.*?<\/altcha-widget>/is',
            self::placeholderText(),
            $html
        );

        return \is_string($result) ? $result : $html;
    }

    /**
     * 难度预设: [PBKDF2 迭代成本, 初始 counter, 前缀字节数]
     * 客户端期望尝试次数约为 2^(8*前缀字节数) / 2
     */
    public static function difficultyParams(string $difficulty): array
    {
        switch ($difficulty) {
            case 'low':
                return [800, random_int(500, 1500), 1];
            case 'high':
                return [10000, random_int(5000, 15000), 1];
            case 'middle':
            default:
                return [2000, random_int(1000, 3000), 1];
        }
    }

    public static function expireSeconds($minutes): int
    {
        $minutes = max(1, (int) $minutes);
        return $minutes * 60;
    }

    /**
     * @return Config|null 插件配置
     */
    private static function options(): ?Config
    {
        try {
            return Options::alloc()->plugin(self::PLUGIN_NAME);
        } catch (\Throwable $e) {
            // CLI / 环境不完整时走配置默认值
            return null;
        }
    }

    private static function configValue(string $key, $default = null)
    {
        $options = self::options();
        $value = $options->{$key} ?? null;

        if (null === $value || '' === $value || [] === $value) {
            return $default;
        }

        return $value;
    }

    private static function enabled(string $scene): bool
    {
        return in_array($scene, (array) self::configValue('enableActions', ['comment']));
    }

    private static function skipForAdmin(): bool
    {
        $user = User::alloc();
        return $user->hasLogin() && $user->pass('administrator', true);
    }

    private static function autoInject(): bool
    {
        return self::configValue('autoInject', 'enable') === 'enable';
    }

    /**
     * 校验提交的 payload
     *
     * @param string|null $payload
     * @return string|null 错误消息; null 表示验证通过
     */
    private static function checkPayload(?string $payload): ?string
    {
        $secret = self::configValue('hmacSecret', '');
        if (empty($secret)) {
            return '插件尚未配置 HMAC 密钥, 请在后台重新保存插件设置';
        }

        if (empty($payload)) {
            return '请先完成人机验证';
        }

        $altcha = new Altcha($secret, $secret);

        try {
            $result = $altcha->verifySolution(new VerifySolutionOptions(
                payload: $payload,
                algorithm: new Pbkdf2(),
            ));
        } catch (\InvalidArgumentException $e) {
            return '人机验证数据无效, 请刷新页面重试';
        }

        if (!$result->verified) {
            if ($result->expired) {
                return '验证已过期, 请重新验证';
            }
            if ($result->invalidSignature) {
                return '验证签名无效, 请刷新页面重试';
            }
            return '人机验证未通过, 请重试';
        }

        // 防重放: 同一 payload 只允许成功提交一次 (主键抢占, 并发安全)
        return self::consumePayload($payload, self::expireSeconds(self::configValue('expire', 15)));
    }

    /**
     * 消费一次验证解答: 首次插入成功放行, 主键冲突视为重放
     *
     * @return string|null 错误消息; null 表示首次使用
     */
    private static function consumePayload(string $payload, int $ttl): ?string
    {
        $db = Db::get();
        $fingerprint = hash('sha256', $payload);

        try {
            // 机会性清理过期记录
            $db->query($db->delete('table.altcha_used')->where('expiry < ?', time()));
        } catch (Db\Exception $e) {
            self::logStorageError('清理防重放记录失败', $e);
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $db->query($db->insert('table.altcha_used')->rows([
                    'fingerprint' => $fingerprint,
                    'expiry'      => time() + $ttl,
                ]));

                return null;
            } catch (Db\Adapter\SQLException $e) {
                if (self::isDuplicateKeyError($e)) {
                    return '验证信息已被使用, 请刷新页面重新验证';
                }

                // 首次失败可能因表缺失 (如备份恢复后未重新启用插件), 重建一次再试
                if (1 === $attempt) {
                    try {
                        self::createReplayTable();
                        continue;
                    } catch (Db\Exception $ignored) {
                        // 落入下方兜底
                    }
                }

                self::logStorageError('写入防重放记录失败', $e);
                return null; // fail-open: 存储异常不应阻断全部评论
            }
        }

        return null;
    }

    /**
     * 各适配器的重复键错误: Mysqli 1062, PDO '23000', 原生 SQLite 19, Pgsql '23505'
     */
    private static function isDuplicateKeyError(Db\Adapter\SQLException $e): bool
    {
        return in_array($e->getCode(), [1062, '23000', 19, '23505'], true)
            || stripos($e->getMessage(), 'duplicate') !== false
            || stripos($e->getMessage(), 'unique constraint') !== false;
    }

    private static function logStorageError(string $context, \Exception $e): void
    {
        error_log(sprintf('Typecho Altcha plugin: %s (%s: %s)', $context, get_class($e), $e->getMessage()));
    }

    /**
     * 创建防重放表, DDL 按当前适配器选择 (参考 typecho/install/*.sql 的分库写法)
     */
    private static function createReplayTable(): void
    {
        $db = Db::get();
        $table = $db->getPrefix() . 'altcha_used';

        switch ($db->getAdapter()->getDriver()) {
            case 'mysql':
                $statements = [
                    "CREATE TABLE IF NOT EXISTS `{$table}` ("
                        . '`fingerprint` CHAR(64) NOT NULL,'
                        . '`expiry` INT UNSIGNED NOT NULL,'
                        . 'PRIMARY KEY (`fingerprint`),'
                        . 'KEY `expiry` (`expiry`)'
                        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                ];
                break;
            case 'pgsql':
                $statements = [
                    "CREATE TABLE IF NOT EXISTS \"{$table}\" ("
                        . '"fingerprint" CHAR(64) NOT NULL,'
                        . '"expiry" INT NOT NULL,'
                        . 'PRIMARY KEY ("fingerprint")'
                        . ')',
                    "CREATE INDEX IF NOT EXISTS \"{$table}_expiry\" ON \"{$table}\" (\"expiry\")",
                ];
                break;
            default: // sqlite
                $statements = [
                    "CREATE TABLE IF NOT EXISTS \"{$table}\" ("
                        . '"fingerprint" VARCHAR(64) NOT NULL PRIMARY KEY,'
                        . '"expiry" INTEGER NOT NULL'
                        . ')',
                    "CREATE INDEX IF NOT EXISTS \"{$table}_expiry\" ON \"{$table}\" (\"expiry\")",
                ];
                break;
        }

        foreach ($statements as $statement) {
            $db->query($statement, Db::WRITE);
        }
    }

    private static function dropReplayTable(): void
    {
        try {
            $db = Db::get();
            $table = $db->getPrefix() . 'altcha_used';
            $db->query("DROP TABLE IF EXISTS {$table}", Db::WRITE);
        } catch (Db\Exception $e) {
            // 禁用不因删表失败而中断
        }
    }

    private static function loginFailed(string $message)
    {
        Notice::alloc()->set(_t($message), 'error');
        Options::alloc()->response->goBack();
    }

    private static function challengeUrl(): string
    {
        // actionTable 的键为小写 'altcha', 此处必须与 Helper::addAction 注册名一致
        return Common::url('/action/altcha', Options::alloc()->index);
    }

    private static function scriptUrl(): string
    {
        // i18n 版内置多语言翻译, auto 模式下组件按浏览器语言自动切换;
        // en 使用主包 (默认英文, 体积更小)
        $bundle = 'en' === self::configValue('language', 'auto') ? 'altcha.min.js' : 'altcha.i18n.min.js';

        if (self::configValue('scriptSource', 'local') === 'cdn') {
            return self::CDN_BASE . $bundle;
        }

        return Common::url('/usr/plugins/' . self::PLUGIN_NAME . '/assets/' . $bundle, Options::alloc()->siteUrl);
    }

    private static function obfuscationScriptUrl(): string
    {
        if (self::configValue('scriptSource', 'local') === 'cdn') {
            return self::CDN_PLUGINS_BASE . 'obfuscation.plugin.min.js';
        }

        return Common::url('/usr/plugins/' . self::PLUGIN_NAME . '/assets/obfuscation.plugin.min.js', Options::alloc()->siteUrl);
    }

    private static function printScript()
    {
        if (self::$widgetScriptPrinted) {
            return;
        }
        self::$widgetScriptPrinted = true;

        $src = htmlspecialchars(self::scriptUrl());
        echo '<script type="module" src="' . $src . '" async defer></script>';
    }

    /**
     * 组件公共属性 (theme/language)
     */
    private static function widgetAttributes(): string
    {
        $attributes = '';

        $theme = self::configValue('theme', 'auto');
        if ('light' === $theme || 'dark' === $theme) {
            $attributes .= ' theme="' . $theme . '"';
        }

        $language = self::configValue('language', 'auto');
        if ('zh' === $language || 'en' === $language) {
            $attributes .= ' language="' . $language . '"';
        }

        return $attributes;
    }

    /**
     * JS 版 widgetAttributes
     */
    private static function widgetAttributesJs(): string
    {
        $lines = '';

        $theme = self::configValue('theme', 'auto');
        if ('light' === $theme || 'dark' === $theme) {
            $lines .= 'widget.setAttribute("theme", ' . json_encode($theme) . ");\n        ";
        }

        $language = self::configValue('language', 'auto');
        if ('zh' === $language || 'en' === $language) {
            $lines .= 'widget.setAttribute("language", ' . json_encode($language) . ");\n        ";
        }

        return $lines;
    }

    private static function generateSecret(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Exception $e) {
            return md5(uniqid((string) mt_rand(), true));
        }
    }
}
