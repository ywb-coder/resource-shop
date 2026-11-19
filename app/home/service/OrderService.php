<?php
namespace app\home\service;

use app\common\model\Material;
use app\common\model\Order;
use app\common\model\CartItem;
use app\common\model\OrderItem;
use app\common\service\VisitService;
use app\common\service\NotifyService;
use think\facade\Db;

class OrderService
{
    /** 下单后多久未支付就算超时（秒） */
    public const TIMEOUT = 600;

    /**
     * 用户是否已购买某份资料。
     * 走明细表，合并订单和单资料订单都能覆盖。
     */
    public static function hasPurchased(int $userId, int $materialId): bool
    {
        return OrderItem::alias('i')
            ->join('order o', 'o.id = i.order_id')
            ->where('o.user_id', $userId)
            ->where('o.pay_status', 1)
            ->where('i.material_id', $materialId)
            ->count() > 0;
    }

    /** 取用户已支付订单中的某份资料明细，取不到说明没买过 */
    public static function paidItem(int $userId, int $materialId): ?OrderItem
    {
        return OrderItem::alias('i')
            ->join('order o', 'o.id = i.order_id')
            ->where('o.user_id', $userId)
            ->where('o.pay_status', 1)
            ->where('i.material_id', $materialId)
            ->field('i.*')
            ->find();
    }

    /**
     * 关闭超时未支付的订单。
     * 不依赖定时任务：用户查看订单或发起支付时顺手清理自己的那部分，
     * 传 null 则清理全部（给 order:close 命令用）。
     */
    public static function closeExpired(?int $userId = null): int
    {
        $query = Order::where('pay_status', 0)
            ->where('status', 0)
            ->where('create_time', '<=', time() - self::TIMEOUT);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        return $query->update(['status' => 2]);
    }

    public function create(int $userId, int $materialId): array
    {
        return $this->createMulti($userId, [$materialId]);
    }

    /**
     * 合并下单：把多份资料放进同一个订单，一次付清
     *
     * 单份时 material_id 照旧填该资料，保持既有逻辑（后台筛选、小程序）可用；
     * 多份时 material_id 填 0，具体买了什么看 shop_order_item。
     */
    public function createMulti(int $userId, array $materialIds): array
    {
        $materialIds = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        if (!$materialIds) {
            throw new \RuntimeException('请选择要购买的资料');
        }

        $materials = Material::whereIn('id', $materialIds)->where('status', 1)->select();
        if (count($materials) !== count($materialIds)) {
            throw new \RuntimeException('部分资料不存在或已下架，请刷新后重试');
        }

        $result = Db::transaction(function () use ($userId, $materialIds, $materials) {
            // 事务内加锁查重，防并发重复下单
            $bought = OrderItem::alias('i')
                ->join('order o', 'o.id = i.order_id')
                ->where('o.user_id', $userId)
                ->where('o.pay_status', 1)
                ->whereIn('i.material_id', $materialIds)
                ->lock(true)
                ->column('i.material_id');

            if ($bought) {
                // 购物车里混了已买过的，跳过它们继续下单，不要卡住整单
                $materials = $materials->filter(fn ($m) => !in_array($m->id, $bought))->values();
                if ($materials->isEmpty()) {
                    $names = Material::whereIn('id', $bought)->column('title');
                    throw new \RuntimeException('您已购买过：' . implode('、', $names));
                }
            }

            // 卡密商品先看库存，没货不让下单；付款瞬间被别人买空的极端情况由补货补发兜底
            foreach ($materials as $m) {
                if (\app\common\service\CardKeyService::isCard($m) && \app\common\service\CardKeyService::stock((int) $m->id) < 1) {
                    throw new \RuntimeException('「' . $m->title . '」已售罄，请稍后再来');
                }
            }

            $amount = 0.0;
            foreach ($materials as $m) {
                $amount += (float) $m->price;
            }

            $single  = count($materials) === 1 ? $materials[0] : null;
            $orderNo = date('YmdHis') . str_pad((string) $userId, 4, '0', STR_PAD_LEFT) . bin2hex(random_bytes(4));


            $order = new Order();
            $order->save([
                'order_no'        => $orderNo,
                'user_id'         => $userId,
                'material_id'     => $single ? $single->id : 0,
                'title'           => $single ? $single->title : ($materials[0]->title . ' 等 ' . count($materials) . ' 份资料'),
                'cover'           => $single ? $single->cover : ($materials[0]->cover ?: ''),
                'amount'          => $amount,
                'delivery_type'   => $single ? $single->delivery_type : 0,
                'pay_status'      => 0,
                'status'          => 0,
                'platform'        => VisitService::currentPlatform(),
            ]);

            foreach ($materials as $m) {
                (new OrderItem())->save([
                    'order_id'      => $order->id,
                    'order_no'      => $orderNo,
                    'material_id'   => $m->id,
                    'title'         => $m->title,
                    'cover'         => $m->cover,
                    'price'         => $m->price,
                    'delivery_type' => $m->delivery_type,
                    'create_time'   => time(),
                ]);
            }

            $result = $order->toArray();
            $result['skipped'] = $bought; // 已购买过、本次未计入的资料
            return $result;
        });

        // 通知放在事务提交之后：外部 HTTP 请求不该占着数据库事务
        NotifyService::orderCreated(Order::find($result['id']));
        return $result;
    }


    // 免费资料直接完成订单
    public function completeFree(string $orderNo, int $userId): void
    {
        $affected = Order::where('order_no', $orderNo)
            ->where('user_id', $userId)
            ->where('amount', 0)
            ->update(['pay_status' => 1, 'status' => 1, 'pay_time' => time()]);

        if ($affected) {
            // 0 元的卡密商品也要发卡
            $order = Order::where('order_no', $orderNo)->find();
            if ($order) {
                (new \app\common\service\CardKeyService())->assignForOrder($order);
                // 免费领取也算一次下单，飞书上留个痕
                NotifyService::orderFree($order);
            }

            // 领到手了就从购物车移走
            $ids = OrderItem::where('order_no', $orderNo)->column('material_id');
            if ($ids) {
                CartItem::where('user_id', $userId)->whereIn('material_id', $ids)->delete();
            }
        }
    }
}
