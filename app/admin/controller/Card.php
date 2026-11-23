<?php
namespace app\admin\controller;

use app\admin\service\CardKeyAdminService;
use app\common\service\CardKeyService;
use app\common\service\LogService;

/** 卡密仓库 */
class Card extends Base
{
    private CardKeyAdminService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->checkLogin();
        $this->service = new CardKeyAdminService();
    }

    public function index(): \think\Response
    {
        return $this->view('card/index', [
            'currentMenu' => 'card',
            'pageTitle'   => '卡密仓库',
            'materialId'  => (int) input('get.material_id', 0),
        ]);
    }

    public function products(): \think\Response
    {
        return $this->success($this->service->products());
    }

    public function list(): \think\Response
    {
        $page     = max(1, (int) input('get.page', 1));
        $pageSize = min(100, max(1, (int) input('get.page_size', 20)));
        return $this->success($this->service->list(input('get.'), $page, $pageSize));
    }

    public function import(): \think\Response
    {
        $materialId = (int) input('post.material_id');
        try {
            $r = (new CardKeyService())->import($materialId, (string) input('post.text', ''));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        LogService::record('卡密仓库', '导入', "商品 ID {$materialId} 导入 {$r['added']} 张，重复跳过 {$r['duplicated']} 张，补发 {$r['backfilled']} 单");
        return $this->success($r);
    }

    public function delete(): \think\Response
    {
        $ids = (array) input('post.ids/a', []);
        $n   = $this->service->deleteUnsold($ids);
        LogService::record('卡密仓库', '删除', "删除未售卡密 {$n} 张");
        return $this->success(['deleted' => $n]);
    }

    /** POST /admin/card/rebind  把已兑换的卡密改绑到另一个账号 */
    public function rebind(): \think\Response
    {
        $id = (int) input('post.id');
        $account = (string) input('post.account', '');
        if (!$id) {
            return $this->error('参数错误');
        }
        try {
            $r = $this->service->rebind($id, $account);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return $this->success($r, $r['changed'] ? '已改绑到 ' . $r['nickname'] : '这张卡本来就绑在这个账号上');
    }

    /** POST /admin/card/unbind  视频课买家换设备：清掉这张卡绑过的设备指纹 */
    public function unbind(): \think\Response
    {
        $id = (int) input('post.id');
        if (!$id) {
            return $this->error('参数错误');
        }
        \app\common\service\VideoService::unbindCard($id);
        LogService::record('卡密仓库', '解绑设备', "卡密 ID {$id} 清空设备绑定，允许在新设备重新兑换");
        return $this->success();
    }

    /** GET /admin/card/export?material_id=&status=  导出 CSV，Excel 能直接打开 */
    public function export(): \think\Response
    {
        $materialId = (int) input('get.material_id');
        $status     = (string) input('get.status', '');
        $rows = $this->service->exportRows($materialId, $status);
        LogService::record('卡密仓库', '导出', "导出商品 ID {$materialId} 的卡密 " . count($rows) . ' 张');

        $csv = "\xEF\xBB\xBF卡号,密码,状态,订单ID,售出时间,导入批次\n"; // BOM：Excel 认 UTF-8
        foreach ($rows as $r) {
            $cells = [$r['card_no'], $r['card_secret'], $r['status'] ? '已售' : '未售', $r['order_id'] ?: '',
                $r['sold_time'] ? date('Y-m-d H:i', (int) $r['sold_time']) : '', $r['batch_no']];
            // 以 = + @ 开头的单元格 Excel 会当公式执行，前面垫个单引号
            $csv .= implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', preg_replace('/^([=+@])/', "'$1", (string) $c)) . '"', $cells)) . "\n";
        }
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="card-keys-' . $materialId . '-' . date('YmdHis') . '.csv"',
        ]);
    }
}
