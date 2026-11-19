<?php
namespace app\common\service;

use app\common\model\Order;

/**
 * 飞书群机器人通知。
 *
 * 和企业微信那套是并列的两条通道，谁配了地址谁就发，互不影响。
 * 通知只是锦上添花，任何失败都只写日志 —— 绝不把下单、注册带崩。
 */
class FeishuNotifyService
{
    /** 飞书机器人固定域名，只认它，避免后台被填成别的地址 */
    private const ALLOW_HOST = 'open.feishu.cn';

    /** 最近一次失败原因，后台「发送测试」直接拿它给提示 */
    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function webhook(): string
    {
        return trim((string) SystemConfigService::get('feishu_webhook', ''));
    }

    /** 事件 → 后台开关字段 */
    public static function switchKey(string $event): string
    {
        return match ($event) {
            'order'   => 'feishu_notify_order',
            'paid'    => 'feishu_notify_paid',
            'free'    => 'feishu_notify_free',
            'user'    => 'feishu_notify_user',
            'comment' => 'feishu_notify_comment',
            default   => '',
        };
    }

    public static function enabled(string $event): bool
    {
        if (self::webhook() === '') {
            return false;
        }
        $key = self::switchKey($event);
        if ($key === '') {
            return false;
        }
        return (string) SystemConfigService::get($key, '1') === '1';
    }

    /** 新订单（待付款） */
    public static function orderCreated(Order $order): void
    {
        if (!self::enabled('order') || (float) $order->amount <= 0) {
            return;
        }
        self::send('🛒 新订单（待付款）', self::orderLines($order), 'orange');
    }

    /** 订单已付款 */
    public static function orderPaid(Order $order): void
    {
        if (!self::enabled('paid')) {
            return;
        }
        self::send('✅ 订单已付款', self::orderLines($order), 'green');
    }

    /** 免费资料领取成功 */
    public static function orderFree(Order $order): void
    {
        if (!self::enabled('free')) {
            return;
        }
        self::send('🎁 免费资料被领取', self::orderLines($order), 'wathet');
    }

    /** 新用户注册 */
    public static function userRegistered(array $user): void
    {
        if (!self::enabled('user')) {
            return;
        }
        $account = (string) ($user['phone'] ?? '') ?: (string) ($user['email'] ?? '');
        $lines = [
            '**昵称**：' . (string) ($user['nickname'] ?? ''),
            '**账号**：' . self::mask($account),
            '**来源 IP**：' . (string) ($user['register_ip'] ?? ''),
            '**时间**：' . date('Y-m-d H:i:s'),
        ];
        self::send('👤 新用户注册', implode("\n", $lines), 'blue');
    }

    /** 用户提问 / 留言 */
    public static function questionCreated(array $question, string $nickname = ''): void
    {
        if (!self::enabled('comment')) {
            return;
        }
        $content = trim((string) ($question['content'] ?? ''));
        if (mb_strlen($content) > 300) {
            $content = mb_substr($content, 0, 300) . '…';
        }
        $lines = ['**内容**：' . $content];
        if ($nickname !== '') {
            $lines[] = '**用户**：' . $nickname;
        }
        if (!empty($question['contact'])) {
            $lines[] = '**联系方式**：' . $question['contact'];
        }
        $lines[] = '**时间**：' . date('Y-m-d H:i:s');
        self::send('💬 收到新的提问', implode("\n", $lines), 'purple');
    }

    /**
     * 后台「发送测试」。
     *
     * @return array{ok:bool,msg:string}
     */
    public static function test(): array
    {
        $url = self::webhook();
        if ($url === '') {
            return ['ok' => false, 'msg' => '还没填飞书机器人 Webhook 地址'];
        }
        if (!self::isValidWebhook($url)) {
            return ['ok' => false, 'msg' => '地址不合法，必须是 https://open.feishu.cn/open-apis/bot/v2/hook/ 开头'];
        }
        $ok = self::send(
            '🔔 飞书通知已接通',
            "收到这条消息，说明下单、付款、注册、提问都会推到本群。\n**时间**：" . date('Y-m-d H:i:s'),
            'blue'
        );
        return [
            'ok'  => $ok,
            'msg' => $ok ? '已发送，请到飞书群确认' : ('发送失败：' . (self::$lastError ?: '未知原因')),
        ];
    }

    /**
     * 发一条卡片消息，卡片被拒时自动退回纯文本。
     *
     * @return bool 是否发送成功
     */
    public static function send(string $title, string $markdown, string $template = 'blue'): bool
    {
        $url = self::webhook();
        if ($url === '') {
            return false;
        }
        if (!self::isValidWebhook($url)) {
            self::$lastError = 'webhook 地址不合法';
            LogService::record('通知', '配置异常', '飞书 webhook 地址不合法，已跳过推送');
            return false;
        }

        $card = [
            'config'   => ['wide_screen_mode' => true],
            'header'   => [
                'template' => $template,
                'title'    => ['tag' => 'plain_text', 'content' => $title],
            ],
            'elements' => [
                ['tag' => 'div', 'text' => ['tag' => 'lark_md', 'content' => $markdown]],
                ['tag' => 'hr'],
                ['tag' => 'note', 'elements' => [['tag' => 'plain_text', 'content' => '知集科技 · 自动通知']]],
            ],
        ];

        if (self::post($url, ['msg_type' => 'interactive', 'card' => $card])) {
            return true;
        }

        // 群里禁用了卡片、或卡片结构被拒时，退回纯文本，保证消息一定能到
        $plain = $title . "\n" . str_replace('**', '', $markdown);
        return self::post($url, ['msg_type' => 'text', 'content' => ['text' => $plain]]);
    }

    /** 必须是公网 https，且只认飞书机器人自己的域名 */
    public static function isValidWebhook(string $url): bool
    {
        if (!UrlSecurityService::isPublicHttpUrl($url)) {
            return false;
        }
        $parts = parse_url($url);
        return strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === self::ALLOW_HOST;
    }

    private static function post(string $url, array $payload): bool
    {
        // 机器人开了「签名校验」才需要，没填就不带这两个字段
        $secret = trim((string) SystemConfigService::get('feishu_secret', ''));
        if ($secret !== '') {
            $timestamp = (string) time();
            $payload['timestamp'] = $timestamp;
            $payload['sign']      = base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, '', true));
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                // 通知不能拖慢下单，超时压到几秒
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false, // 跟跳转会绕开前面的地址校验
            ]);
            $response = curl_exec($ch);
            $error    = curl_error($ch);

            if ($response === false) {
                self::$lastError = $error;
                LogService::record('通知', '推送失败', '飞书：' . $error);
                return false;
            }

            $data = json_decode((string) $response, true) ?: [];
            // 新版返回 code/msg，旧版返回 StatusCode/StatusMessage，两种都认
            $code = $data['code'] ?? $data['StatusCode'] ?? -1;
            if ((int) $code !== 0) {
                self::$lastError = (string) ($data['msg'] ?? $data['StatusMessage'] ?? $response);
                LogService::record('通知', '推送失败', '飞书：' . self::$lastError);
                return false;
            }
            self::$lastError = '';
            return true;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            LogService::record('通知', '推送异常', '飞书：' . $e->getMessage());
            return false;
        }
    }

    private static function orderLines(Order $order): string
    {
        $lines = [
            '**资料**：' . $order->title,
            '**金额**：¥' . number_format((float) $order->amount, 2, '.', ''),
            '**单号**：' . $order->order_no,
        ];

        $user = null;
        try {
            $user = $order->user;
        } catch (\Throwable $e) {
            // 关联查不到不影响通知主体
        }
        if ($user) {
            $account = (string) ($user->phone ?? '') ?: (string) ($user->email ?? '');
            $lines[] = '**用户**：' . (string) $user->nickname . ($account !== '' ? '（' . self::mask($account) . '）' : '');
        }

        $lines[] = '**时间**：' . date('Y-m-d H:i:s');
        return implode("\n", $lines);
    }

    /** 手机号/邮箱脱敏，群里看到也不至于泄露完整账号 */
    private static function mask(string $account): string
    {
        $account = trim($account);
        if ($account === '') {
            return '未填';
        }
        if (preg_match('/^1[3-9]\d{9}$/', $account)) {
            return substr($account, 0, 3) . '****' . substr($account, -4);
        }
        if (str_contains($account, '@')) {
            [$name, $domain] = explode('@', $account, 2);
            $keep = mb_substr($name, 0, 1);
            return $keep . '***@' . $domain;
        }
        return mb_strlen($account) > 6 ? mb_substr($account, 0, 3) . '***' : $account;
    }
}
