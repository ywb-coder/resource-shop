<?php
namespace app\home\service;

use app\common\model\CartItem;
use app\common\model\Order;
use app\common\model\OrderItem;
use app\common\model\PaymentConfig;
use app\common\service\AlipayService;
use app\common\service\LogService;
use app\common\service\NotifyService;
use app\common\service\WechatPayService;

class PayService
{
    /**
     * 标记订单已支付。用带 pay_status=0 条件的原子更新做幂等：
     * 支付渠道会重复推送同一笔通知，加上主动查单也会走到这里，
     * 影响行数为 0 说明已被处理过，直接跳过后续动作，
     * 避免重复处理同一笔订单。
     */
    public static function markPaid(Order $order, int $channel, string $tradeNo): bool
    {
        $affected = Order::where('order_no', $order->order_no)
            ->where('pay_status', 0)
            ->update([
                'pay_status' => 1,
                'pay_type'   => $channel,
                'pay_time'   => time(),
                'trade_no'   => $tradeNo,
                'status'     => 1,
            ]);
        if ($affected === 0) {
            return false;
        }

        $channelName = match ($channel) { 2 => '微信', default => '支付宝' };
        LogService::record('订单', '支付完成', "订单号：{$order->order_no} 渠道：{$channelName} 交易号：{$tradeNo}");

        // 买到手了就从购物车移走，免得用户回去看还挂着
        $boughtIds = OrderItem::where('order_id', $order->id)->column('material_id');
        if ($boughtIds) {
            CartItem::where('user_id', $order->user_id)
                ->whereIn('material_id', $boughtIds)
                ->delete();
        }

        // 卡密商品：付款后从仓库发卡（没货就挂着，补货后补发）
        (new \app\common\service\CardKeyService())->assignForOrder($order);

        NotifyService::orderPaid(Order::find($order->id) ?: $order);

        return true;
    }

    /**
     * 主动查询支付宝交易状态，已付款就补单。
     *
     * 异步通知可能因为回调地址填错、外网不通、服务器抖动而丢失，
     * 只靠 notify 会出现「用户付了钱、订单还挂着待支付」。
     * 用户回跳到结果页或轮询支付状态时再查一次，把这类单子捞回来。
     *
     * @return bool 本次调用是否把订单从未支付改成了已支付
     */
    public static function syncAlipay(string $orderNo): bool
    {
        if ($orderNo === '') {
            return false;
        }

        // 只处理还挂着的单子；已支付的直接跳过，不必打支付宝接口
        $order = Order::where('order_no', $orderNo)->where('pay_status', 0)->find();
        if (!$order) {
            return false;
        }

        // 轮询会高频打到这里，节流一下，避免把商户的查询接口刷到限流
        $lockKey = 'pay_sync_' . $orderNo;
        if (\think\facade\Cache::get($lockKey)) {
            return false;
        }
        \think\facade\Cache::set($lockKey, 1, 5);

        $payConfig = PaymentConfig::where('type', 1)->where('status', 1)->find();
        if (!$payConfig) {
            return false;
        }
        $config = json_decode($payConfig->config, true) ?: [];
        if (trim((string) ($config['app_id'] ?? '')) === '' || trim((string) ($config['private_key'] ?? '')) === '') {
            return false;
        }

        try {
            $res = (new AlipayService($config))->apiCall('alipay.trade.query', ['out_trade_no' => $orderNo]);
        } catch (\Throwable $e) {
            LogService::record('订单', '主动查单失败', "订单号：{$orderNo}，原因：" . $e->getMessage());
            return false;
        }

        // 没付款时支付宝返回 ACQ.TRADE_NOT_EXIST，属正常情况，不记日志
        $tradeStatus = (string) ($res['trade_status'] ?? '');
        if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return false;
        }

        // 金额校验，和异步通知一条标准，防低价攻击
        $paidAmount  = number_format((float) ($res['total_amount'] ?? 0), 2, '.', '');
        $orderAmount = number_format((float) $order->amount, 2, '.', '');
        if ($paidAmount !== $orderAmount) {
            LogService::record('订单', '金额异常', "订单号：{$orderNo} 应付：{$orderAmount} 实付：{$paidAmount}（主动查单）");
            return false;
        }

        $paid = self::markPaid($order, 1, (string) ($res['trade_no'] ?? ''));
        if ($paid) {
            LogService::record('订单', '主动查单补单', "订单号：{$orderNo} 异步通知未到，已由主动查询补单");
        }
        return $paid;
    }

    /**
     * 主动查询微信交易状态，已付款就补单。和 syncAlipay 一个用途：
     * 小程序端支付完没有回跳页面，只能靠前端轮询，异步通知丢了就得靠这里捞。
     *
     * @return bool 本次调用是否把订单从未支付改成了已支付
     */
    public static function syncWechat(string $orderNo): bool
    {
        if ($orderNo === '') {
            return false;
        }

        $order = Order::where('order_no', $orderNo)->where('pay_status', 0)->find();
        if (!$order) {
            return false;
        }

        // 轮询会高频打到这里，节流一下，避免把商户的查询接口刷到限流
        $lockKey = 'pay_sync_wx_' . $orderNo;
        if (\think\facade\Cache::get($lockKey)) {
            return false;
        }
        \think\facade\Cache::set($lockKey, 1, 5);

        $payConfig = PaymentConfig::where('type', 2)->where('status', 1)->find();
        if (!$payConfig) {
            return false;
        }
        $config = json_decode($payConfig->config, true) ?: [];
        if (WechatPayService::missingFields($config)) {
            return false;
        }

        try {
            $res = (new WechatPayService($config))->queryOrder($orderNo);
        } catch (\Throwable $e) {
            // 未支付时微信返回 ORDER_NOT_EXIST（HTTP 404），属正常情况，不记日志
            $msg = $e->getMessage();
            if (!str_contains($msg, 'ORDER_NOT_EXIST') && !str_contains($msg, '订单不存在')) {
                LogService::record('订单', '主动查单失败', "订单号：{$orderNo}，原因：" . $msg);
            }
            return false;
        }

        if (($res['trade_state'] ?? '') !== 'SUCCESS') {
            return false;
        }

        // 金额校验，和异步通知一条标准，防低价攻击。微信单位是分
        $paidFen  = (int) ($res['amount']['total'] ?? 0);
        $orderFen = (int) round((float) $order->amount * 100);
        if ($paidFen !== $orderFen) {
            LogService::record('订单', '金额异常', "订单号：{$orderNo} 应付：{$orderFen}分 实付：{$paidFen}分（主动查单）");
            return false;
        }

        $paid = self::markPaid($order, 2, (string) ($res['transaction_id'] ?? ''));
        if ($paid) {
            LogService::record('订单', '主动查单补单', "订单号：{$orderNo} 异步通知未到，已由主动查询补单");
        }
        return $paid;
    }
}
