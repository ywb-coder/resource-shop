<?php
namespace app\common\service;

use app\common\model\CardKey;
use app\common\model\Material;
use app\common\model\Order;
use app\common\model\OrderItem;
use think\facade\Db;

/**
 * 卡密仓库：导入、库存、付款后发卡、补货后补发。
 *
 * 一笔订单里的每份卡密商品发一张卡。发卡在事务里加行锁取「最早导入的未售卡」，
 * 并发付款也不会两单拿到同一张。付款时恰好没货的订单先挂着，
 * 后台补货导入后由 backfill() 按付款先后补发。
 */
class CardKeyService
{
    public const GOODS_CARD = 2;

    /** 视频课：卡密由站外售卖，站内只负责兑换核销 */
    public const GOODS_VIDEO = 3;

    public static function isCard(Material $material): bool
    {
        return (int) $material->goods_type === self::GOODS_CARD;
    }

    /**
     * 这份商品用不用「卡密仓库」。
     *
     * 卡密商品（激活码软件）和视频课都从同一个仓库导卡，区别只在交付环节：
     * 前者付款后自动发卡，后者要买家自己输卡密兑换观看权限。
     */
    public static function supportsCards($material): bool
    {
        return $material instanceof Material
            && in_array((int) $material->goods_type, [self::GOODS_CARD, self::GOODS_VIDEO], true);
    }

    /** 未售库存 */
    public static function stock(int $materialId): int
    {
        return CardKey::where('material_id', $materialId)->where('status', 0)->count();
    }

    /**
     * 解析导入文本：一行一张卡。
     * 「卡号 密码」用空格、Tab、逗号或「----」分隔；只有一段的是单码卡。
     *
     * 同一个卡号在文本里出现多次，只认第一次（后面的可能是误粘）。
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function parse(string $text): array
    {
        $cards = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = preg_split('/\s*(?:----|,|，|\t|\s)\s*/u', $line, 2);
            $no = trim($parts[0]);
            if ($no === '' || mb_strlen($no) > 255) continue;
            $cards[$no] ??= [$no, mb_substr(trim($parts[1] ?? ''), 0, 255)];
        }
        return array_values($cards);
    }

    /**
     * 导入卡密，重复的（同商品已存在的卡号）跳过。导入后顺手给缺货的订单补发。
     *
     * @return array{added: int, duplicated: int, backfilled: int}
     */
    public function import(int $materialId, string $text): array
    {
        $material = Material::find($materialId);
        if (!$material || !self::supportsCards($material)) {
            throw new \RuntimeException('请选择卡密商品');
        }
        $cards = self::parse($text);
        if (!$cards) {
            throw new \RuntimeException('没有识别到卡密，请一行一张');
        }

        $exists = CardKey::where('material_id', $materialId)
            ->whereIn('card_no', array_column($cards, 0))
            ->column('card_no');
        $exists = array_flip($exists);
        $batch  = date('YmdHis');
        $rows   = [];
        foreach ($cards as [$no, $secret]) {
            if (isset($exists[$no])) continue;
            $rows[] = ['material_id' => $materialId, 'card_no' => $no, 'card_secret' => $secret, 'batch_no' => $batch, 'create_time' => time()];
        }
        if ($rows) {
            (new CardKey())->saveAll($rows);
        }
        $lines = count(array_filter(preg_split('/\r\n|\r|\n/', $text), fn ($l) => trim($l) !== ''));
        return [
            'added'      => count($rows),
            'duplicated' => $lines - count($rows),
            'backfilled' => $this->backfill($materialId),
        ];
    }

    /**
     * 订单付款后发卡：订单里每份卡密商品各发一张。重复调用安全（已发过的跳过）。
     * 没货时不报错，等补货后 backfill() 补发。
     */
    public function assignForOrder(Order $order): void
    {
        $items = OrderItem::where('order_id', $order->id)->column('material_id');
        if (!$items) return;
        $cardIds = Material::whereIn('id', $items)->where('goods_type', self::GOODS_CARD)->column('id');
        foreach ($cardIds as $materialId) {
            $this->assignOne((int) $order->id, (int) $order->user_id, (int) $materialId);
        }
    }

    /** @return bool 是否发出（或此前已发过） */
    private function assignOne(int $orderId, int $userId, int $materialId): bool
    {
        return Db::transaction(function () use ($orderId, $userId, $materialId) {
            if (CardKey::where('order_id', $orderId)->where('material_id', $materialId)->count()) {
                return true;
            }
            $card = CardKey::where('material_id', $materialId)->where('status', 0)->order('id', 'asc')->lock(true)->find();
            if (!$card) {
                LogService::record('卡密', '缺货', "订单 ID {$orderId} 的卡密商品 ID {$materialId} 无库存，待补货后补发");
                return false;
            }
            $card->save(['status' => 1, 'order_id' => $orderId, 'user_id' => $userId, 'sold_time' => time()]);
            return true;
        });
    }

    /** 给已付款、还没拿到卡的订单补发，按付款先后。返回补发张数 */
    public function backfill(int $materialId): int
    {
        $pending = OrderItem::alias('i')
            ->join('order o', 'o.id = i.order_id')
            ->where('i.material_id', $materialId)
            ->where('o.pay_status', 1)
            ->whereNotExists(function ($q) use ($materialId) {
                $q->table(CardKey::getTable())->alias('c')->whereColumn('c.order_id', 'o.id')->where('c.material_id', $materialId);
            })
            ->order('o.pay_time', 'asc')
            ->field('o.id, o.user_id')
            ->select()
            ->toArray();

        $n = 0;
        foreach ($pending as $o) {
            if (!$this->assignOne((int) $o['id'], (int) $o['user_id'], $materialId)) break;
            $n++;
        }
        return $n;
    }

    /** 用户买到的这份商品的卡密（可能买过多次） */
    public static function cardsOf(int $userId, int $materialId): array
    {
        return CardKey::where('user_id', $userId)->where('material_id', $materialId)->where('status', 1)
            ->order('id', 'asc')->field('card_no, card_secret, sold_time')->select()->toArray();
    }

    /** 发货信息：卡密 + 使用地址 + 说明；还没发到卡的 cards 为空，前端提示补货中 */
    public static function delivery(Material $material, int $userId): array
    {
        return [
            'cards'     => array_map(fn ($c) => ['no' => $c['card_no'], 'secret' => $c['card_secret']], self::cardsOf($userId, (int) $material->id)),
            'use_url'   => (string) $material->use_url,
            'use_guide' => (string) $material->use_guide,
        ];
    }
}
