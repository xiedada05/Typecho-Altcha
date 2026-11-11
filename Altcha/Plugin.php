<?php
/**
 * ALTCHA 人机验证插件
 *
 * 由 Typecho-Turnstile 插件爆改而来, 验证后端替换为 ALTCHA
 * 工作量证明 (Proof-of-Work), 无外部服务依赖, 验证过程完全本地完成。
 *
 * @package Altcha
 * @author xiedada05
 * @version 2.0.1
 * @link https://github.com/xiedada05/Typecho-Altcha
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

namespace TypechoPlugin\Altcha;

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/Form/Range.php';

use AltchaOrg\Altcha\Algorithm\DeriveKeyInterface;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\Algorithm\ShaAlgorithm;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\HmacAlgorithm;
use AltchaOrg\Altcha\Obfuscator;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Typecho\Common;
use Typecho\Config;
use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Number;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use TypechoPlugin\Altcha\Form\Range;
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
     * 前端组件版本 (npm: altcha), scripts/fetch-assets.sh 与 CDN 地址均以此为准
     */
    public const WIDGET_VERSION = '3.2.2';

    /**
     * 是否启用救援模式
     * 启用后, 将跳过登录验证, 适用于无法通过验证时临时排查问题
     */
    private const RESCUE_MODE = false;

    /**
     * 默认前端组件 CDN (可在插件设置中自定义, 需填到 altcha 包的 dist 一级)
     */
    private const DEFAULT_CDN_DIST = 'https://cdn.jsdelivr.net/npm/altcha@' . self::WIDGET_VERSION . '/dist';

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

        $difficulty = new Range('difficulty', [
            'min'  => 500,
            'max'  => 20000,
            'step' => 100,
        ], 2000, _t('验证难度 (滑块)'), _t('求解时每次尝试的计算成本: PBKDF2 系列为迭代次数, SHA 系列为哈希轮数 (相同数值下 SHA 明显更轻快)。范围 500 ~ 20000, 默认 2000 相当于旧版「中」档 (低=800 / 高=10000), 越高对机器人越昂贵, 对访客设备的要求也越高。'));

        $algorithmLabels = [
            'PBKDF2/SHA-256' => _t('PBKDF2/SHA-256 (默认, 推荐)'),
            'PBKDF2/SHA-384' => _t('PBKDF2/SHA-384'),
            'PBKDF2/SHA-512' => _t('PBKDF2/SHA-512'),
            'SHA-256'        => _t('SHA-256 (最轻量)'),
            'SHA-384'        => _t('SHA-384'),
            'SHA-512'        => _t('SHA-512'),
        ];

        $algorithm = new Select('algorithm', $algorithmLabels, 'PBKDF2/SHA-256', _t('哈希算法'),
            _t('签发挑战与浏览器解题使用的哈希算法。前端组件支持 SHA 与 PBKDF2 系列; 相同难度下 PBKDF2 每次尝试更昂贵, SHA 系列对低端设备更友好。修改后新挑战立即生效, 已下发的旧挑战按其自身参数校验, 不受影响。'));

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
        ], 'local', _t('组件脚本来源'), _t('本地打包时验证流程不产生任何第三方网络请求; git 克隆部署且未执行资产下载脚本时自动回退 CDN'));

        $cdnBase = new Text('cdnBase', null, '',
            _t('自定义 CDN 地址'),
            _t('组件脚本来源为 CDN (含自动回退) 时使用, 留空为官方源 jsDelivr。填写到 altcha 包的 dist 一级, 末尾斜杠可省略, 例如: https://registry.npmmirror.com/altcha/3.2.2/files/dist 。版本号需与组件版本一致 (当前 ' . self::WIDGET_VERSION . '), 可用镜像: jsDelivr / npmmirror / 自建反代'));

        $autoInject = new Radio('autoInject', [
            'enable'  => _t('启用'),
            'disable' => _t('禁用'),
        ], 'enable', _t('自动插入评论验证组件'), _t('禁用后需要在主题 comments.php 的评论表单内自行调用 &lt;?php \TypechoPlugin\Altcha\Plugin::output(); ?&gt;'));

        $xmlrpcBypass = new Radio('xmlrpcBypass', [
            'enable'  => _t('启用'),
            'disable' => _t('禁用'),
        ], 'disable', _t('XML-RPC 豁免 (供合法机器人)'), _t('启用后, 下方名单内的用户通过 XML-RPC (MetaWeblog 博客客户端、第三方 App 等机器人接口) 调用登录与评论时不再要求人机验证 —— 机器人无法完成浏览器工作量证明。'
            . ' &lt;strong&gt;请务必为这些账号设置强密码, 并遵循最小权限原则: 机器人通常只需要 subscriber / contributor 权限, 不要使用管理员账号, 名单外账号调用 XML-RPC 照常拦截。&lt;/strong&gt; '
            . '注意: 若站点同时开启了 Typecho 内置的评论反垃圾保护, XML-RPC 评论会被核心拦截, 与本插件无关。'));

        $xmlrpcUsers = new Text('xmlrpcUsers', null, '',
            _t('XML-RPC 豁免名单'),
            _t('允许免人机验证的登录用户名, 多个用逗号或换行分隔。名单内账号在网页端操作时照常需要验证, 仅 XML-RPC 调用被豁免。'));

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
        $form->addInput($algorithm);
        $form->addInput($expire);
        $form->addInput($theme);
        $form->addInput($language);
        $form->addInput($scriptSource);
        $form->addInput($cdnBase);
        $form->addInput($autoInject);
        $form->addInput($xmlrpcBypass);
        $form->addInput($xmlrpcUsers);
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

        // XML-RPC 豁免: 名单内用户的机器人评论 (需已通过 XML-RPC 认证)
        if (self::xmlRpcBypassEnabled() && self::isXmlRpcCall() && self::currentUserInXmlRpcList()) {
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

        // XML-RPC 请求无法携带工作量证明: 名单内用户凭原生密码校验豁免,
        // 名单外直接判定失败 (返回 XML fault 而非页面重定向)
        if (self::isXmlRpcCall()) {
            if (self::xmlRpcBypassEnabled() && self::xmlRpcLoginAllowed($password)) {
                return true;
            }

            error_log('Typecho Altcha plugin: 拒绝了名单外用户的 XML-RPC 登录');
            return false;
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
     * 难度参数: [单次推导成本, 前缀字节数]
     * 滑块存数值成本; 兼容旧版字符串预设 (low/middle/high)。
     * 客户端期望尝试次数约为 2^(8*前缀字节数) / 2
     *
     * 注意: 此处刻意不生成「初始 counter」。签发时一旦传入 counter,
     * createChallenge() 会发布服务端预先算好的解答 keySignature,
     * verifySolution() 随之启用不校验 counter 的快路径, 使同一挑战存在
     * 无限多个可独立通过验证的解答 (BUG-R2-C1-A1-H2 的根因)。
     * 不传 counter 时 createChallenge() 会为 keyPrefix 生成随机前缀,
     * 快路径不会被启用, 每次签发仍要求客户端完成一次真实 PoW。
     */
    public static function difficultyParams($difficulty): array
    {
        $cost = match (true) {
            'low' === $difficulty    => 800,
            'middle' === $difficulty => 2000,
            'high' === $difficulty   => 10000,
            is_numeric($difficulty)  => (int) $difficulty,
            default                  => 2000,
        };

        $cost = min(20000, max(500, $cost));

        return [$cost, 1];
    }

    /**
     * 可选哈希算法 (前端 altcha 组件可在浏览器内求解的全部算法)
     *
     * @return array<string>
     */
    public static function supportedAlgorithms(): array
    {
        return [
            'PBKDF2/SHA-256',
            'PBKDF2/SHA-384',
            'PBKDF2/SHA-512',
            'SHA-256',
            'SHA-384',
            'SHA-512',
        ];
    }

    /**
     * 算法名 → 密钥推导实现, 未知名称回退默认 PBKDF2/SHA-256
     */
    public static function algorithm(?string $name): DeriveKeyInterface
    {
        return match ($name) {
            'PBKDF2/SHA-384' => new Pbkdf2(HmacAlgorithm::SHA384),
            'PBKDF2/SHA-512' => new Pbkdf2(HmacAlgorithm::SHA512),
            'SHA-256'        => new Sha(ShaAlgorithm::SHA256),
            'SHA-384'        => new Sha(ShaAlgorithm::SHA384),
            'SHA-512'        => new Sha(ShaAlgorithm::SHA512),
            default          => new Pbkdf2(HmacAlgorithm::SHA256),
        };
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
     * XML-RPC 豁免是否开启
     */
    private static function xmlRpcBypassEnabled(): bool
    {
        return self::configValue('xmlrpcBypass', 'disable') === 'enable';
    }

    /**
     * 当前请求是否为 XML-RPC 调用 (端点固定为 /action/xmlrpc)
     */
    private static function isXmlRpcCall(): bool
    {
        try {
            $url = (string) Options::alloc()->request->getRequestUrl();
        } catch (\Exception $e) {
            return false;
        }

        return self::isXmlRpcUrl($url);
    }

    /**
     * 按 URL 路径判断是否为 XML-RPC 端点
     *
     * 只按路径判定。查询串完全由客户端控制, 而 Typecho 路由只匹配
     * getPathInfo() (已剥离查询串), 因此 <permalink>/comment?action=xmlrpc
     * 仍然是一条普通网页评论; 若让查询串参与判定, 名单内账号只需附加该参数
     * 即可跳过 PoW (BUG-R2-C2-A1-H1)。
     */
    private static function isXmlRpcUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return 1 === preg_match('#/action/xmlrpc/?$#i', $path);
    }

    /**
     * 豁免名单 (登录用户名, 逗号/换行等分隔)
     *
     * @return array<string>
     */
    private static function xmlRpcUsers(): array
    {
        $raw = (string) self::configValue('xmlrpcUsers', '');
        $users = preg_split('/[\s,;、]+/u', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $users))));
    }

    /**
     * 当前登录用户是否在豁免名单
     */
    private static function currentUserInXmlRpcList(): bool
    {
        try {
            $user = User::alloc();
        } catch (\Exception $e) {
            return false;
        }

        if (!$user->hasLogin()) {
            return false;
        }

        return \in_array((string) ($user->name ?? ''), self::xmlRpcUsers(), true);
    }

    /**
     * 提取 XML-RPC 认证时正在登录的用户名
     * (User::login 触发 hashValidate 时用户名仅在调用栈参数中)
     */
    private static function xmlRpcLoginName(): ?string
    {
        foreach (debug_backtrace(0, 10) as $frame) {
            if ('login' === ($frame['function'] ?? '')
                && 'Widget\User' === \ltrim((string) ($frame['class'] ?? ''), '\\')
                && isset($frame['args'][0])
                && \is_string($frame['args'][0])) {
                return $frame['args'][0];
            }
        }

        return null;
    }

    /**
     * 校验 XML-RPC 登录是否可以豁免:
     * 正在登录的用户名必须在名单内, 且凭据通过原生密码校验
     */
    private static function xmlRpcLoginAllowed(string $password): bool
    {
        $name = self::xmlRpcLoginName();

        if (null === $name || '' === $name || !\in_array($name, self::xmlRpcUsers(), true)) {
            return false;
        }

        $row = Db::get()->fetchRow(
            Db::get()->select()->from('table.users')->where('name = ?', $name)->limit(1)
        );

        if (empty($row)) {
            return false;
        }

        if ('$P$' === substr((string) $row['password'], 0, 3)) {
            $hasher = new PasswordHash(8, true);
            return $hasher->checkPassword($password, (string) $row['password']);
        }

        return Common::hashValidate($password, (string) $row['password']);
    }

    /**
     * 校验提交的 payload
     *
     * @param mixed $payload POST 字段, 只接受字符串 (数组型 altcha[] 不能绕过, 也不能触发 500)
     * @return string|null 错误消息; null 表示验证通过
     */
    private static function checkPayload($payload): ?string
    {
        $secret = self::configValue('hmacSecret', '');
        if (empty($secret)) {
            return '插件尚未配置 HMAC 密钥, 请在后台重新保存插件设置';
        }

        if (!\is_string($payload) || '' === $payload) {
            return '请先完成人机验证';
        }

        $altcha = new Altcha($secret, $secret);

        // 按挑战自身声明的算法校验: 挑战参数已被 HMAC 签名无法伪造,
        // 且后台切换算法后已下发的旧挑战仍能按原参数通过验证
        try {
            $decoded = Payload::fromBase64($payload);
            $result = $altcha->verifySolution(new VerifySolutionOptions(
                payload: $payload,
                algorithm: self::algorithm($decoded->challenge->parameters->algorithm),
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

        // 防重放必须以「挑战」为单位消费, 而不是原始 POST 字节或单一解答:
        // - 同一解答存在无限种等价字节编码 (solution.time / 未知键 / 键序 / 空白 /
        //   \uXXXX 转义), 直接哈希原始字符串会让每次重放都得到新主键 (BUG-R2-C1-A1-H1);
        // - 同一挑战也存在海量等价解答 (快路径不校验 counter; 全量路径下约 2^24 个
        //   counter 满足 1 字节前缀), 对「解答」哈希同样挡不住第二次提交 (BUG-R2-C1-A1-H2)。
        // 每次下发都使用全新 random_bytes(16) nonce/salt, 挑战签名因此逐次唯一,
        // sha256(挑战签名) 才是正确的「一次下发一次动作」指纹。
        $challengeSignature = $decoded->challenge->signature;
        if (null === $challengeSignature || '' === $challengeSignature) {
            // 配置了 HMAC 密钥时, 验签通过必然带签名; 此处仅为防御性兜底
            return '人机验证未通过, 请重试';
        }

        // 记录 TTL 取挑战自身已签名的 expiresAt, 而不是当前后台配置:
        // 否则管理员在挑战有效期内调小 expire 时, 防重放记录会早于挑战失效被清理,
        // 从而留下可重放的窗口。
        $expiresAt = $decoded->challenge->parameters->expiresAt;
        $ttl = null !== $expiresAt
            ? max(1, $expiresAt - time() + 1)
            : self::expireSeconds(self::configValue('expire', 15));

        return self::consumePayload($challengeSignature, $ttl);
    }

    /**
     * 消费一次挑战: 首次插入成功放行, 主键冲突视为重放
     *
     * 指纹基于挑战签名 (每次签发唯一), 与提交字节及解答数量无关。
     * 存储层异常必须 fail-closed: 放行等于跳过防重放校验。SQLite 的
     * SQLITE_BUSY ("database is locked", code 5) 可被未认证的 trackback
     * 写入压力诱发, 且不会命中重复键判定 (BUG-R2-C2-A2-H1)。
     *
     * @param string $challengeSignature 已验证通过的挑战签名
     * @return string|null 错误消息; null 表示首次使用
     */
    private static function consumePayload(string $challengeSignature, int $ttl): ?string
    {
        $db = Db::get();
        $fingerprint = hash('sha256', 'altcha-challenge-v1|' . $challengeSignature);

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

                self::logStorageError('写入防重放记录失败 (fail-closed)', $e);
                return '验证服务暂时不可用, 请稍后重试';
            }
        }

        // 理论不可达: 两次尝试均已有明确返回; 保持 fail-closed 语义
        return '验证服务暂时不可用, 请稍后重试';
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

    /**
     * 前端组件 CDN 地址: 优先取插件设置中的自定义地址, 未配置时用官方 jsDelivr 源
     */
    private static function cdnDistBase(): string
    {
        $custom = trim((string) self::configValue('cdnBase', ''));

        return '' !== $custom ? rtrim($custom, '/') : self::DEFAULT_CDN_DIST;
    }

    private static function scriptUrl(): string
    {
        // i18n 版内置多语言翻译, auto 模式下组件按浏览器语言自动切换;
        // en 使用主包 (默认英文, 体积更小)
        $bundle = 'en' === self::configValue('language', 'auto') ? 'altcha.min.js' : 'altcha.i18n.min.js';

        // git 克隆但未执行 scripts/fetch-assets.php 时, 本地文件不存在则回退 CDN
        if (self::configValue('scriptSource', 'local') === 'cdn'
            || !\is_file(__DIR__ . '/assets/' . $bundle)) {
            return self::cdnDistBase() . '/main/' . $bundle;
        }

        return Common::url('/usr/plugins/' . self::PLUGIN_NAME . '/assets/' . $bundle, Options::alloc()->siteUrl);
    }

    private static function obfuscationScriptUrl(): string
    {
        if (self::configValue('scriptSource', 'local') === 'cdn'
            || !\is_file(__DIR__ . '/assets/obfuscation.plugin.min.js')) {
            return self::cdnDistBase() . '/plugins/obfuscation.plugin.min.js';
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
