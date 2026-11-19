<?php
namespace app\home\controller;
use app\home\service\MaterialService;
use app\common\service\SystemConfigService;
class Index extends Base
{
    /** 分类图标：按分类 id 指定，模板里直接取 */
    public const CAT_ICONS = [
        5  => 'fa-code',
        6  => 'fa-palette',
        7  => 'fa-briefcase',
        25 => 'fa-pen-nib',
        26 => 'fa-tower-broadcast',
        27 => 'fa-coins',
        28 => 'fa-users',
        29 => 'fa-bullhorn',
        30 => 'fa-brain',
        31 => 'fa-cart-shopping',
        32 => 'fa-graduation-cap',
        33 => 'fa-house-chimney',
        34 => 'fa-scale-balanced',
    ];

    public function index(): \think\Response
    {
        $service = new MaterialService();
        // 首页展示按推荐权重的头部内容，而不是单纯最新的 12 条
        $products = $service->getTop(12);
        // 首页精选：优先人工指定的热门，其次取排在最前的一条
        $featured = \app\common\model\Material::where('status', 1)
            ->where('is_hidden', 0)
            ->where('is_hot', 1)
            ->where('category_id', '<>', 33)
            ->order('sort', 'desc')
            ->find();
        if (!$featured) {
            $featured = $products[0] ?? null;
        }
        if ($featured) {
            $featured = is_array($featured) ? $featured : $featured->toArray();
            $featured['category_name'] = \app\common\model\Category::where('id', $featured['category_id'] ?? 0)->value('name') ?: '技能';
        }

        $counts = $service->categoryCounts();
        $typeCounts = $service->resTypeCounts();

        // 有内容的技能子分类，做首页分类网格
        $skillCats = [];
        foreach ($service->getCategories() as $c) {
            if ((int) $c['parent_id'] !== 1) {
                continue;
            }
            $n = (int) ($counts[$c['id']] ?? 0);
            if ($n <= 0) {
                continue;
            }
            $skillCats[] = [
                'id'    => (int) $c['id'],
                'name'  => $c['name'],
                'count' => $n,
                'icon'  => self::CAT_ICONS[(int) $c['id']] ?? 'fa-shapes',
            ];
        }
        usort($skillCats, fn ($a, $b) => $b['count'] <=> $a['count']);

        // 三大板块入口
        $sections = [
            [
                'name' => 'Skill 技能', 'en' => 'AI SKILLS',
                'desc' => '下载压缩包，直接发给 AI 就能用',
                'count' => (int) ($typeCounts['skill'] ?? 0), 'unit' => '个技能',
                'url' => '/list?res_type=skill', 'icon' => 'fa-wand-magic-sparkles', 'tone' => 'blue',
            ],
            [
                'name' => '软件与资料', 'en' => 'TOOLS & DOCS',
                'desc' => '效率工具、模板、行业报告',
                'count' => (int) (($typeCounts['doc'] ?? 0) + ($typeCounts['software'] ?? 0)), 'unit' => '个资源',
                'url' => '/list?res_type=doc', 'icon' => 'fa-folder-open', 'tone' => 'teal',
            ],
            [
                'name' => '心理测试题', 'en' => 'ASSESSMENTS',
                'desc' => '自助式测评，做完立即出报告',
                'count' => (int) ($typeCounts['test'] ?? 0), 'unit' => '份测评',
                'url' => '/test', 'icon' => 'fa-clipboard-list', 'tone' => 'pink',
            ],
        ];

        // 三大板块里没有内容的（比如软件资料暂时为 0）不展示空板块，补一张场景导航卡
        $sections = array_values(array_filter($sections, fn ($x) => $x['count'] > 0));
        if (count($sections) < 3) {
            $sections[] = [
                'name' => '按场景找', 'en' => 'BY SCENARIO',
                'desc' => count($skillCats) . ' 个场景分类，从营销到电商',
                'count' => count($skillCats), 'unit' => '个分类', 'url' => '#cats',
                'icon' => 'fa-layer-group', 'tone' => 'amber',
            ];
        }

        // 精选那条如果就在展示列表里，去掉重复
        if ($featured) {
            $fid = is_array($featured) ? (int) $featured['id'] : (int) $featured->id;
            $products = array_values(array_filter($products, fn ($p) => (int) $p['id'] !== $fid));
        }

        return $this->view('index/index', [
            'products' => $products,
            'featured' => $featured,
            'sections' => $sections,
            'skillCats' => $skillCats,
            'skillTotal' => (int) ($typeCounts['skill'] ?? 0),
            'testTotal' => (int) ($typeCounts['test'] ?? 0),
            'resourceCount' => $counts[0] ?? 0,
            'seo_title' => SystemConfigService::get('site_name', '知集科技') . ' - 开源 AI 技能与数字资源库',
            'seo_description' => '知集科技收录上千个开源可商用的 AI 技能（Skill），覆盖电商运营、营销销售、自媒体、文案写作、办公效率；另有心理测评与效率工具，下载即用，全部免费。',
        ]);
    }
}
