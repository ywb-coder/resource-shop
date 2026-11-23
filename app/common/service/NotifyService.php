<?php
namespace app\common\service;

use app\common\model\Order;
use app\common\model\User;

/**
 * 通知总入口。
 *
 * 业务代码只认这一层：下单、付款、注册、提问调这里，
 * 具体走企业微信还是飞书由各自的开关决定，加渠道不用再动业务逻辑。
 * 每个渠道单独 try/catch，一个渠道挂了不影响另一个。
 */
class NotifyService
{
    public static function orderCreated(?Order $order): void
    {
        if (!$order) {
            return;
        }
        self::guard(static fn() => WecomNotifyService::orderCreated($order));
        self::guard(static fn() => FeishuNotifyService::orderCreated($order));
    }

    public static function orderPaid(?Order $order): void
    {
        if (!$order) {
            return;
        }
        self::guard(static fn() => WecomNotifyService::orderPaid($order));
        self::guard(static fn() => FeishuNotifyService::orderPaid($order));
    }

    /** 免费资料领取：只推飞书，企业微信保持原来的节奏不动 */
    public static function orderFree(?Order $order): void
    {
        if (!$order) {
            return;
        }
        self::guard(static fn() => FeishuNotifyService::orderFree($order));
    }

    public static function userRegistered(array $user): void
    {
        self::guard(static fn() => FeishuNotifyService::userRegistered($user));
    }

    /** 视频课卡密兑换成功：目前只推飞书 */
    public static function cardUnlocked(array $info): void
    {
        self::guard(static fn() => FeishuNotifyService::cardUnlocked($info));
    }

    public static function questionCreated(array $question, int $userId = 0): void
    {
        $nickname = '';
        if ($userId > 0) {
            try {
                $nickname = (string) (User::where('id', $userId)->value('nickname') ?? '');
            } catch (\Throwable $e) {
                // 查不到昵称就不显示，不影响通知本身
            }
        }
        self::guard(static fn() => FeishuNotifyService::questionCreated($question, $nickname));
    }

    /** 通知失败绝不能把主流程带崩 */
    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            LogService::record('通知', '推送异常', $e->getMessage());
        }
    }
}
