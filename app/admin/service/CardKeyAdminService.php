<?php
namespace app\admin\service;

use app\common\model\CardKey;
use app\common\model\Material;
use app\common\model\Order;
use think\facade\Db;

/**
 * 后台卡密仓库：按商品看库存、列表筛选、删除未售、导出
 */
class CardKeyAdminService
{
    /** 卡密商品及各自库存 */
    public function products(): array
    {
        $rows = Material::whereIn('goods_type', [
                \app\common\service\CardKeyService::GOODS_CARD,
                \app\common\service\CardKeyService::GOODS_VIDEO, // 视频课也从这里导卡
            ])
            ->order('id', 'desc')->field('id, title, cover, price, status, goods_type')->select()->toArray();
        $counts = CardKey::group('material_id, status')->field('material_id, status, COUNT(*) AS n')->select()->toArray();
        $map = [];
        foreach ($counts as $c) {
            $map[$c['material_id']][(int) $c['status']] = (int) $c['n'];
        }
        foreach ($rows as &$r) {
            $r['unsold'] = $map[$r['id']][0] ?? 0;
            $r['sold']   = $map[$r['id']][1] ?? 0;
        }
        unset($r);
        return $rows;
    }

    public function list(array $params, int $page, int $pageSize): array
    {
        $query = CardKey::order('id', 'desc');
        if (!empty($params['material_id'])) {
            $query->where('material_id', (int) $params['material_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }
        if (!empty($params['keyword'])) {
            $query->whereLike('card_no', '%' . $params['keyword'] . '%');
        }
        $total = (clone $query)->count();
        $list  = $query->page($page, $pageSize)->select()->toArray();

        // 已售的带上订单号，方便对账
        $orderNos = Order::whereIn('id', array_filter(array_column($list, 'order_id')))->column('order_no', 'id');

        // 视频课的卡密还要看「绑了几台设备」，超限的买家会来要求解绑
        $devices = [];
        $soldIds = array_filter(array_column($list, 'id'));
        if ($soldIds) {
            $grants = \app\common\model\VideoGrant::whereIn('card_id', $soldIds)->field('card_id, devices')->select()->toArray();
            foreach ($grants as $g) {
                $d = json_decode((string) $g['devices'], true);
                $devices[(int) $g['card_id']] = is_array($d) ? count($d) : 0;
            }
        }

        // 卡密兑换后权益归账号，列表上要能直接看到「这张卡绑到谁身上了」
        $userIds = array_filter(array_column($list, 'user_id'));
        $userNames = [];
        if ($userIds) {
            $userNames = \app\common\model\User::whereIn('id', array_unique($userIds))->column('nickname', 'id');
        }

        foreach ($list as &$row) {
            $row['order_no']  = $orderNos[$row['order_id']] ?? '';
            $row['sold_time'] = $row['sold_time'] ? date('Y-m-d H:i', (int) $row['sold_time']) : '';
            $row['device_count'] = $devices[(int) $row['id']] ?? 0;
            $row['user_name'] = $userNames[(int) $row['user_id']] ?? '';
        }
        unset($row);
        return compact('list', 'total', 'page', 'pageSize');
    }

    /**
     * 把已兑换的卡密改绑到另一个账号。
     *
     * 真实场景：买家拿小号兑换了，或者客服代兑时登错了账号。
     * 卡密本身已经作废，所以这里只搬「权益归属」，顺便清空设备绑定让新账号重新认设备。
     */
    public function rebind(int $cardId, string $account): array
    {
        $account = trim($account);
        if ($account === '') {
            throw new \RuntimeException('请输入买家账号');
        }
        $user = \app\common\model\User::where('phone', $account)->whereOr('email', $account)->find();
        if (!$user) {
            $user = \app\common\model\User::where('nickname', $account)->find();
        }
        if (!$user) {
            throw new \RuntimeException('没有找到这个账号，请让买家确认手机号 / 邮箱');
        }
        if ((int) $user->status !== 1) {
            throw new \RuntimeException('这个账号已被禁用，换一个吧');
        }

        $card = CardKey::find($cardId);
        if (!$card) {
            throw new \RuntimeException('卡密不存在');
        }
        $grant = \app\common\model\VideoGrant::where('card_id', $cardId)->find();
        if (!$grant) {
            throw new \RuntimeException('这张卡还没被兑换，不需要改绑');
        }
        if ((int) $grant->user_id === (int) $user->id) {
            return ['nickname' => $user->nickname, 'user_id' => (int) $user->id, 'changed' => false];
        }

        Db::transaction(function () use ($card, $grant, $user) {
            $card->save(['user_id' => (int) $user->id]);
            $grant->save([
                'user_id' => (int) $user->id,
                'devices' => json_encode([]),
            ]);
        });

        \app\common\service\LogService::record(
            '卡密仓库',
            '改绑账号',
            "卡密 {$card->card_no} 改绑到账号「{$user->nickname}」(ID {$user->id})"
        );

        return ['nickname' => $user->nickname, 'user_id' => (int) $user->id, 'changed' => true];
    }

    /** 只删未售的；已售的是发货凭证，不能删 */
    public function deleteUnsold(array $ids): int
    {
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) return 0;
        return CardKey::whereIn('id', $ids)->where('status', 0)->delete();
    }

    /** @return array<int, array> 导出用的行 */
    public function exportRows(int $materialId, string $status): array
    {
        $query = CardKey::where('material_id', $materialId)->order('id', 'asc');
        if ($status !== '') {
            $query->where('status', (int) $status);
        }
        return $query->field('card_no, card_secret, status, order_id, sold_time, batch_no')->select()->toArray();
    }
}
