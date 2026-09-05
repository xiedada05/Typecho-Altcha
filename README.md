# Typecho Altcha 人机验证插件

由 [Typecho-Turnstile](https://github.com/NKXingXh/Typecho_Turnstile) 插件爆改而来,将验证后端从 Cloudflare Turnstile 替换为 [ALTCHA](https://altcha.org) 工作量证明(Proof-of-Work)。

## 与 Turnstile 的区别

| | Turnstile | Altcha (本插件) |
|---|---|---|
| 验证方式 | Cloudflare 远程校验 | 本地工作量证明 (PoW) |
| 外部请求 | 需访问 Cloudflare | **无任何外部请求** |
| 隐私 | 访客数据提交给 Cloudflare | 不收集任何数据 |
| 部署要求 | 需注册 Cloudflare 获取密钥 | 零配置,启用即用 |
| 机器人成本 | — | 每次提交需消耗真实算力解题 |

## 工作原理

1. 浏览器从 `/action/altcha` 获取由 HMAC 签名的一次性挑战(PBKDF2 工作量证明);
2. 访客浏览器用 Web Worker 求解挑战(耗时约 0.1 ~ 2 秒,取决于难度设置);
3. 提交表单时附带解答,服务端验证 HMAC 签名与工作量,**无需任何外部调用**;
4. 挑战带有效期(默认 15 分钟),且每个已通过的解答会被记录到数据库防重放表 `{前缀}altcha_used`(主键抢占,并发安全),同一解答只能成功提交一次,过期记录自动清理;启用插件时自动建表,禁用时自动删表,兼容 SQLite / MySQL / PostgreSQL。多机共享同一数据库的部署同样生效。

> 防重放表写入异常时插件会放行请求并在 PHP error_log 记录警告(fail-open),避免存储故障阻断全部评论;若日志中出现相关报错,请尝试重新启用插件以重建数据表。

## 环境要求

- Typecho 1.3+
- PHP 8.1+(启用时强制校验)

## 安装

**方式一(推荐)**:从 [Releases](https://github.com/xiedada05/Typecho-Altcha/releases) 下载 `Altcha-vX.X.X.zip`,解压到 `usr/plugins/`(内含全部依赖,开箱即用)。

**方式二**:克隆仓库(需 `--recursive` 拉取 ALTCHA 库 submodule):

```sh
git clone --recursive https://github.com/xiedada05/Typecho-Altcha.git
cp -r Typecho-Altcha/Altcha /path/to/typecho/usr/plugins/
```

克隆方式不含前端组件产物(`assets/`,不进仓库):未拉取时插件自动回退到 CDN,开箱可用;想保持零外部请求,用 PHP 下载一次(Windows 下命令相同,反斜杠路径):

```sh
php scripts/fetch-assets.php          # Linux / macOS
php scripts\fetch-assets.php          # Windows (在仓库根目录)
```

默认从 jsDelivr 官方源下载,网络受限可 `--cdn-base=` 指定镜像,如:`php scripts/fetch-assets.php --cdn-base=https://registry.npmmirror.com/altcha/3.2.2/files/dist`。

1. 将 `Altcha` 目录放入 `usr/plugins/` 后,在控制台启用插件,HMAC 密钥会自动生成;
2. 在插件设置中勾选需要保护的场景(评论 / 登录)。

### 评论

默认自动注入:插件会在评论表单(`#respond`)内自动插入验证组件,**无需修改主题**。

如果主题结构特殊,可在插件设置中禁用「自动插入」,然后手动在主题 `comments.php` 的评论表单内添加:

```php
<?php \TypechoPlugin\Altcha\Plugin::output(); ?>
```

### 登录

在插件设置中勾选「登录」后,登录页会自动插入验证组件,无需修改任何文件。

如遇组件故障导致无法登录,可将插件源码中 `Plugin.php` 的 `RESCUE_MODE` 常量改为 `true` 临时跳过登录验证。

## 内容保护(防爬取)

在文章里用 `<altcha-protect>` 标记敏感内容(邮箱、电话、下载链接等),输出时自动 AES-256-GCM 加密:爬虫抓到的源码只有密文,访客点击按钮、浏览器解出内嵌的小工作量证明后才能看到原文。功能默认启用,需要 PHP openssl 扩展。

**Markdown 模式和 HTML 模式写法相同:**

```
联系我:<altcha-protect>mailto:admin@example.com</altcha-protect>

电话:<altcha-protect label="查看电话">138-0013-8000</altcha-protect>
```

- 按钮文案默认「验证后查看」,可用 `label` 属性覆盖单个标记,也可在插件设置里改全局默认;
- 内容以**纯文本**加密;以 `mailto:` / `tel:` / `sms:` / `https?:` 开头时,揭示后会自动变成可点击链接;
- Markdown 模式下标记内部的 Markdown 会先渲染、再去掉标签后加密;含下划线的邮箱建议用 `mailto:` 写法,避免被解析成斜体;保护块内不要留空行;
- 列表摘要与 RSS 订阅中,受保护内容显示为占位文案「[受保护内容]」(可配置),不泄露明文也不泄露密文;
- 不支持嵌套标记;标记会作用于代码演示,想展示标签本身请转义书写;
- 插件停用或缺少 openssl 扩展时,标记原样输出,内容**明文可见、不丢失**(浏览器不识别自定义标签,但会显示其中文本);
- 安全边界:混淆防的是无脑批量爬取,决心明确的爬虫可以像真人一样点击解题 —— 它是防爬措施,不是访问控制。

## 配置项

| 配置 | 说明 |
|---|---|
| HMAC 密钥 | 签发/校验挑战的密钥,启用时自动生成,修改后已下发的挑战立即失效 |
| 启用场景 | 评论 / 登录,可多选 |
| 验证难度 | 低 / 中 / 高,越高对机器人越昂贵,对访客设备要求也越高 |
| 挑战有效期 | 默认 15 分钟,过期挑战会被拒绝 |
| 外观主题 / 组件语言 | 亮暗主题与多语言界面(i18n 内置,默认跟随浏览器语言) |
| 组件脚本来源 | 本地打包(默认,零外部请求)/ CDN;git 克隆部署未拉取产物时自动回退 CDN |
| 自定义 CDN 地址 | 组件脚本来源为 CDN 时使用,留空为官方 jsDelivr 源;填到 altcha 包的 `dist` 一级即可接 npmmirror、自建反代等镜像 |
| 自动插入评论组件 | 关闭后需主题手动调用 `output()` |
| 内容保护 | 启用/禁用 `<altcha-protect>` 内容加密(默认启用) |
| 保护内容按钮文案 | 占位按钮的全局默认文案,可用 `label` 属性覆盖 |
| 摘要占位文案 | 列表摘要与 RSS 中受保护内容的替代文本 |

## 升级 ALTCHA 库

服务端库 `Altcha/lib` 是指向 [altcha-org/altcha-lib-php](https://github.com/altcha-org/altcha-lib-php) 的 git submodule(当前固定在 v2.1.0)。上游发布修复后:

```sh
git submodule update --remote Altcha/lib   # 跟进上游默认分支
cd Altcha/lib && git checkout vX.Y.Z && cd ..   # 或固定到指定 tag
git add Altcha/lib && git commit -m "chore: bump altcha-lib"
```

打 `v*` tag 推送后,GitHub Actions 会自动组装发布包(拉取 submodule + `php scripts/fetch-assets.php` 下载前端组件)并创建 Release。前端组件版本单一来源是 `Plugin.php` 的 `WIDGET_VERSION` 常量,升级时改它、重跑 fetch 脚本即可,产物不进仓库。

## 致谢

- 原插件: [Typecho-Turnstile](https://github.com/NKXingXh/Typecho_Turnstile) (AGPL-3.0) © NKXingXh
- 服务端库: [altcha-lib-php](https://github.com/altcha-org/altcha-lib-php) (MIT,以 git submodule 引入,发布包内置)
- 前端组件: [altcha-org/altcha](https://github.com/altcha-org/altcha) v3.2.2 (MIT,构建产物不入库,由 CI 打包时组装,版本见 `Plugin.php` 的 `WIDGET_VERSION`)

## 开发测试

`tests/` 目录包含开发用测试脚本(需要本地测试站点):

```sh
php tests/closed-loop.php     # 签发→解题→验证 闭环 + 篡改/过期/换密钥用例
php tests/e2e.php             # 对真实站点的评论接口全场景回归
php tests/obfuscation.php     # 内容保护: 加解密往返/标记解析/RSS 分支/摘要防泄露
```

## 许可证

本插件衍生自 [Typecho-Turnstile](https://github.com/NKXingXh/Typecho_Turnstile)(AGPL-3.0)。依据 GNU 对 AGPL-3.0 与 GPLv3 的兼容性条款,本项目整体以 **GPL-3.0-or-later(GPLv3 及更新版本)** 发布,详见 [LICENSE](LICENSE)。ALTCHA 服务端库与前端组件遵循其原始 MIT 许可证(见 `lib/LICENSE.txt` 与 [altcha.org](https://altcha.org))。

Copyright © 2026 xiedada05 (https://github.com/xiedada05)
