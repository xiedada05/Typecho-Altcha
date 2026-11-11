<?php

namespace TypechoPlugin\Altcha;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use Widget\ActionInterface;
use Widget\Base;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * ALTCHA 挑战下发端点
 *
 * 由 Helper::addAction 注册为 /action/altcha,
 * 前端组件在用户开始验证时请求此地址获取新挑战。
 *
 * @package Altcha
 */
class Action extends Base implements ActionInterface
{
    /**
     * 只需全局选项,读取插件配置
     *
     * @param int $components
     */
    protected function initComponents(int &$components)
    {
        $components = self::INIT_OPTIONS;
    }

    public function execute()
    {
    }

    /**
     * 下发一个一次性挑战
     */
    public function action()
    {
        if (!$this->request->isGet()) {
            $this->response->setStatus(405);
            $this->response->throwJson(['error' => 'Method Not Allowed']);
        }

        $options = $this->options->plugin(Plugin::PLUGIN_NAME);
        $secret = $options->hmacSecret;

        if (empty($secret)) {
            $this->response->setStatus(500);
            $this->response->throwJson(['error' => 'ALTCHA 插件尚未配置 HMAC 密钥, 请在后台重新保存插件设置']);
        }

        [$cost, $keyPrefixLength] = Plugin::difficultyParams($options->difficulty);

        $altcha = new Altcha($secret, $secret);
        $challenge = $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: Plugin::algorithm($options->algorithm ?? null),
            cost: $cost,
            // 不传 counter: 传入时库会发布 keySignature 并启用「不校验 counter」的
            // 验证快路径, 使同一挑战存在无限多个可独立通过验证的解答。
            // 传空 keyPrefix 让库生成随机前缀, 快路径不启用, 每次签发都要求真实 PoW。
            keyPrefix: '',
            keyPrefixLength: $keyPrefixLength,
            expiresAt: time() + Plugin::expireSeconds($options->expire),
        ));

        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->throwJson($challenge->toArray());
    }
}
