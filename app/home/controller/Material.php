<?php
namespace app\home\controller;

use app\common\service\SystemConfigService;
use app\home\service\MaterialService as MS;
use app\home\service\OrderService;
use app\home\service\UserService;

class Material extends Base
{
    public function list(): \think\Response
    {
        $service = new MS();
        $params  = array_merge([
            'q'           => '',
            'category_id' => '',
            'sort'        => '',
            'res_type'    => '',
            'page'        => 1,
        ], input('get.') ?: []);
        $page = max(1, (int) $params['page']);
        $result     = $service->getList($params, $page, 20);
        // 首屏那几条封面立即加载，其余保持懒加载
        foreach (array_slice(array_keys($result['list']), 0, 10) as $i) {
            $result['list'][$i]['eager'] = true;
        }
        $categories = $service->getCategories();
        $currentCat = null;
        if (!empty($params['category_id'])) {
            foreach ($categories as $c) {
                if ($c['id'] == $params['category_id']) { $currentCat = $c; break; }
            }
        }

        $title = '全部资源';
        if ($currentCat) $title = $currentCat['name'];
        if (!empty($params['q'])) $title = '"' . $params['q'] . '" 的搜索结果';
        $typeNames = ['skill' => 'Skill 技能', 'doc' => '资料文档', 'software' => '软件工具', 'test' => '心理测试题', 'course' => '视频课'];
        if (!empty($params['res_type']) && isset($typeNames[$params['res_type']])) {
            $title = $typeNames[$params['res_type']];
        }

        $counts = $service->categoryCounts();

        return $this->view('material/list', [
            'result'     => $result,
            'params'     => $params,
            'typeNames'  => $typeNames,
            'currentCat' => $currentCat,
            'catCounts'  => $counts,
            'typeCounts' => $service->resTypeCounts(),
            'catNav'     => $service->categoryNav((int) $params['category_id'], $counts),
            'skillCats'  => $service->childrenWithCount(1, $counts),
            'testCats'   => $service->childrenWithCount(3, $counts),
            'seo_title'  => $title . ' - ' . SystemConfigService::get('site_name', '知集科技'),
            'searchKw'   => $params['q'] ?? '',
        ]);
    }

    public function detail(): \think\Response
    {
        $id       = (int) input('param.id');
        $service  = new MS();
        $material = $service->getDetail($id);
        if (!$material) {
            return response('资料不存在', 404);
        }

        $hasPurchased = false;
        if ($this->userId) {
            $hasPurchased = (new UserService())->hasPurchased($this->userId, $id);
        }

        $tagList = !empty($material['tags'])
            ? array_filter(array_map('trim', explode(',', $material['tags'])))
            : [];
        $related = $service->getRelated($id, $material['category_id'], 5);

        // 视频课：这门课是不是已经在这个账号下（卡密课没有订单，只能查授权表）
        $owned = false;
        if ($this->userId && (int) ($material['goods_type'] ?? 1) === \app\common\service\VideoService::GOODS_VIDEO) {
            $owned = (bool) \app\common\service\VideoService::grantFor(
                \app\common\model\Material::find($id),
                $this->userId
            );
        }

        // 视频课：详情页要展示「几章几节、总时长」，而不是文件格式
        // 注意 getDetail() 返回的是数组，不能直接传给只认模型的 isVideo()
        $courseStat = null;
        if ((int) ($material['goods_type'] ?? 1) === \app\common\service\VideoService::GOODS_VIDEO) {
            $stat = \app\common\service\VideoService::courseStat(\app\common\model\Material::find($id));
            if ($stat['lessons'] > 0) {
                $h = intdiv($stat['seconds'], 3600);
                $m = intdiv($stat['seconds'] % 3600, 60);
                $courseStat = $stat + [
                    'total_text' => $h > 0 ? sprintf('%d 小时 %d 分', $h, $m) : sprintf('%d 分钟', $m),
                ];
            }
        }

        $title = $material['seo_title'] ?: $material['title'];
        return $this->view('material/detail', [
            'material'     => $material,
            'tagList'      => $tagList,
            'related'      => $related,
            'hasPurchased' => $hasPurchased,
            'owned'        => $owned,
            'courseStat'   => $courseStat,
            'hideTabbar'   => true, // 详情页手机端有自己的吸底购买栏
            // 卡密商品显示库存，普通资料用不到
            'cardStock'    => (int) ($material['goods_type'] ?? 1) === \app\common\service\CardKeyService::GOODS_CARD
                ? \app\common\service\CardKeyService::stock($id) : 0,
            'seo_title'    => $title,
            'seo_keywords' => $material['seo_keywords'] ?: $material['tags'],
            'seo_description' => $material['seo_description'] ?: $material['summary'],
            'seo_canonical'   => request()->domain() . '/detail/' . $id,
        ]);
    }

    public function buy(): \think\Response
    {
        $this->requireLogin();
        // material_id 单买；material_ids 购物车合并下单
        $ids = input('post.material_ids');
        $ids = is_array($ids) ? $ids : array_filter([(int) input('post.material_id')]);

        try {
            $order = (new OrderService())->createMulti($this->userId, $ids);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        // 免费资料直接完成
        if ($order['amount'] == 0) {
            (new OrderService())->completeFree($order['order_no'], $this->userId);
            return $this->success([
                'free'     => true,
                'order_id' => (int) $order['id'],
                'order_no' => $order['order_no'],
                'skipped'  => $order['skipped'] ?? [],
            ]);
        }

        return $this->success([
            'order_id' => (int) $order['id'],
            'order_no' => $order['order_no'],
            'amount'   => $order['amount'],
            'skipped'  => $order['skipped'] ?? [],
        ]);
    }
}
