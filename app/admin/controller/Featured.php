<?php
namespace app\admin\controller;

use app\common\model\Material;
use app\common\service\FeaturedService;
use app\common\service\LogService;

/**
 * 首页「本周精选」轮播位管理。
 *
 * 5 个位置，后台可以手动指定、固定某个位置不被自动覆盖，
 * 也可以点「按下载量更新」立刻重排；每周一 03:00 由 featured:refresh 自动跑一次。
 */
class Featured extends Base
{
    private FeaturedService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new FeaturedService();
    }

    public function index(): \think\Response
    {
        return $this->view('featured/index', [
            'currentMenu' => 'featured',
            'pageTitle'   => '首页精选',
            'breadcrumb'  => [['name' => '内容管理', 'url' => ''], ['name' => '首页精选', 'url' => '']],
        ]);
    }

    /** 页面数据：5 个位 + 本周下载排行 + 开关状态 */
    public function data(): \think\Response
    {
        return $this->success([
            'slots'    => $this->service->slots(),
            'ranking'  => $this->service->rankingCards(7, 10),
            'auto'     => FeaturedService::autoEnabled(),
            'last_run' => FeaturedService::lastRun(),
            'window'   => FeaturedService::WINDOW_DAYS,
        ]);
    }

    /** 搜索可选的资料 */
    public function search(): \think\Response
    {
        $kw = trim((string) input('get.q', ''));
        $query = Material::where('status', 1)
            ->where('is_hidden', 0)
            ->field('id,title,cover,category_id,price');

        if ($kw !== '') {
            if (ctype_digit($kw)) {
                $query->where('id', (int) $kw);
            } else {
                $query->whereLike('title', '%' . $kw . '%');
            }
        }

        $list = $query->order('sort', 'desc')->limit(20)->select()->toArray();

        $cats = [];
        if ($list) {
            $cats = \app\common\model\Category::whereIn('id', array_unique(array_column($list, 'category_id')))
                ->column('name', 'id');
        }
        foreach ($list as &$row) {
            $row['category'] = $cats[(int) $row['category_id']] ?? '技能';
        }
        unset($row);

        return $this->success($list);
    }

    /** 设定某个位置 */
    public function set(): \think\Response
    {
        $position   = (int) input('post.position');
        $materialId = (int) input('post.material_id');
        $pinned     = input('post.pinned');
        try {
            $this->service->setSlot($position, $materialId, $pinned === null ? null : (bool) $pinned);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        LogService::record('首页精选', '设置', "位置 {$position} 设为资料 #{$materialId}");
        return $this->success($this->service->slots());
    }

    public function clear(): \think\Response
    {
        $position = (int) input('post.position');
        $this->service->clearSlot($position);
        LogService::record('首页精选', '清空', "清空位置 {$position}");
        return $this->success($this->service->slots());
    }

    public function pin(): \think\Response
    {
        $position = (int) input('post.position');
        try {
            $pinned = $this->service->togglePin($position);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        LogService::record('首页精选', '固定', "位置 {$position} " . ($pinned ? '固定' : '取消固定'));
        return $this->success(['pinned' => $pinned]);
    }

    /** 每周一自动更新开关 */
    public function auto(): \think\Response
    {
        $on = (string) input('post.enabled', '1') === '1';
        FeaturedService::setAuto($on);
        LogService::record('首页精选', '开关', '每周自动更新：' . ($on ? '开启' : '关闭'));
        return $this->success(['auto' => $on]);
    }

    /** 立刻按下载量重排一次 */
    public function refresh(): \think\Response
    {
        $result = $this->service->refresh();
        LogService::record('首页精选', '刷新', '手动按下载量更新，保留固定 ' . $result['kept'] . ' 个');
        return $this->success([
            'result' => $result,
            'slots'  => $this->service->slots(),
        ], '已按近 7 天下载量更新');
    }
}
