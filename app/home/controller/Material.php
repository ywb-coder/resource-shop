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

        $title = '全部资料';
        if ($currentCat) $title = $currentCat['name'] . ' - 资料列表';
        if (!empty($params['q'])) $title = '"' . $params['q'] . '" 搜索结果';
        $typeNames = ['skill' => 'Skill 技能', 'doc' => '资料文档', 'software' => '软件工具', 'test' => '心理测试题'];
        if (!empty($params['res_type']) && isset($typeNames[$params['res_type']])) {
            $title = $typeNames[$params['res_type']] . ' - 全部列表';
        }

        return $this->view('material/list', [
            'result'     => $result,
            'params'     => $params,
            'currentCat' => $currentCat,
            'catCounts'  => $service->categoryCounts(),
            'typeCounts' => $service->resTypeCounts(),
            'catNav'     => $service->categoryNav((int) $params['category_id']),
            'seo_title'  => $title . ' - ' . SystemConfigService::get('site_name', '资料小铺'),
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

        $title = $material['seo_title'] ?: $material['title'];
        return $this->view('material/detail', [
            'material'     => $material,
            'tagList'      => $tagList,
            'related'      => $related,
            'hasPurchased' => $hasPurchased,
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
