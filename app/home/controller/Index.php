<?php
namespace app\home\controller;
use app\home\service\MaterialService;
use app\common\service\SystemConfigService;
class Index extends Base
{
    public function index(): \think\Response
    {
        $service = new MaterialService();
        $products = $service->getNew(12);
        // 首页头图优先展示人工指定的热门资源，其次退回最新一条
        $featured = \app\common\model\Material::where('status', 1)
            ->where('is_hidden', 0)
            ->where('is_hot', 1)
            ->order('id', 'desc')
            ->find();
        if (!$featured) {
            $featured = $products[0] ?? null;
        }
        return $this->view('index/index', [
            'products' => $products,
            'featured' => $featured,
            'resourceCount' => $service->categoryCounts()[0] ?? 0,
            'seo_title' => SystemConfigService::get('site_name', '资料小铺') . ' - 发现实用的数字资料',
        ]);
    }
}
