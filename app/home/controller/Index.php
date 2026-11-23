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
        35 => 'fa-clapperboard',
    ];

    public function index(): \think\Response
    {
        $service = new MaterialService();
        // 首页展示按推荐权重的头部内容，而不是单纯最新的 12 条
        $products = $service->getTop(12);

        // 首页「本周精选」轮播：后台手工指定的 + 每周一按下载量自动挑的，最多 5 个
        $featuredService = new \app\common\service\FeaturedService();
        $featuredList    = $featuredService->forHome();
        if (!$featuredList) {
            // 还没配过 / 自动任务还没跑过，先按站内推荐顺序兜几个，别让轮播空着
            $featuredList = $featuredService->bootstrap(\app\common\service\FeaturedService::SLOTS);
        }
        $featured = $featuredList[0] ?? null;

        // 模板引擎对三元/函数写法兼容性差，样式类、封面地址这类都在这里算好再交给模板
        foreach ($featuredList as $i => &$f) {
            $f['active_class'] = $i === 0 ? 'is-active' : '';
            $f['loading_attr'] = $i === 0 ? 'eager' : 'lazy';
            $f['dot_index']    = $i;
            $f['cover_url']    = $f['cover'] ? $f['cover'] . '?v=3' : '/static/img/sample-fallback.svg';
            $f['cat']          = $f['category_name'] ?: '技能';
        }
        unset($f);
        $featuredFirst = $featuredList[0] ?? null;

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
                // 优先用后台分类里配置的图标，没配才回落到映射表；新增分类不必再改代码
                'icon'  => trim(str_replace('fa-solid', '', (string) ($c['icon'] ?? '')))
                           ?: (self::CAT_ICONS[(int) $c['id']] ?? 'fa-shapes'),
            ];
        }
        usort($skillCats, fn ($a, $b) => $b['count'] <=> $a['count']);

        // 补充入口：课程和 Skill 都有专属区块，这里不再重复，只放"还有什么"
        $sections = [
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
            [
                'name' => '全部资源', 'en' => 'ALL MATERIALS',
                'desc' => '课程、技能、工具，一站找齐',
                'count' => (int) ($counts[0] ?? 0), 'unit' => '个内容',
                'url' => '/list', 'icon' => 'fa-layer-group', 'tone' => 'amber',
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

        // 精选那几个如果也在下面「最新上架」列表里，去掉重复
        if ($featuredList) {
            $fids = array_map(fn ($f) => (int) $f['id'], $featuredList);
            $products = array_values(array_filter($products, fn ($p) => !in_array((int) $p['id'], $fids, true)));
        }

        // 首页主推：录播课。章节数、课时数、总时长一次查完算好，模板里直接取
        $courses     = $this->courseCards(6);        $courseTotal = \app\common\model\Material::where('goods_type', 3)
            ->where('status', 1)->where('is_hidden', 0)->count();
        $lessonTotal = (int) \app\common\model\VideoLesson::where('status', 1)->count();
        $lessonSecs  = (int) \app\common\model\VideoLesson::where('status', 1)->sum('duration');

        // 课程已有专属区块（且价格是卡密），最新上架里不再重复露脸
        $products = array_values(array_filter(
            $products,
            fn ($p) => (int) ($p['goods_type'] ?? 0) !== 3
        ));

        return $this->view('index/index', [
            'products' => $products,
            'courses' => $courses,
            'courseTotal' => $courseTotal,
            'lessonTotal' => $lessonTotal,
            'lessonHours' => \app\common\service\VideoService::hoursText($lessonSecs),
            'featured' => $featured,
            'featuredList' => $featuredList,
            'featuredFirst' => $featuredFirst,
            'sections' => $sections,
            'skillCats' => $skillCats,
            'skillTotal' => (int) ($typeCounts['skill'] ?? 0),
            'testTotal' => (int) ($typeCounts['test'] ?? 0),
            'resourceCount' => $counts[0] ?? 0,
            'seo_title' => SystemConfigService::get('site_name', '知集科技') . ' - AI 实战录播课与技能库',
            'seo_description' => '知集科技做 AI 实战录播课：从任务设计到 Agent 落地，全程真人录屏、逐节跟练，卡密解锁长期可看；另收录开源可商用的 AI 技能（Skill）、效率工具与心理测评。',
        ]);
    }

    /**
     * 首页课程卡：一节一行地算章节/课时/时长，避免每张卡查一次库
     */
    private function courseCards(int $limit = 6): array
    {
        $rows = \app\common\model\Material::where('goods_type', 3)
            ->where('status', 1)
            ->where('is_hidden', 0)
            ->order('sort', 'desc')->order('id', 'desc')
            ->limit($limit)->select()->toArray();
        if (!$rows) {
            return [];
        }

        $ids     = array_map(fn ($r) => (int) $r['id'], $rows);
        $lessons = \app\common\model\VideoLesson::whereIn('material_id', $ids)
            ->where('status', 1)
            ->field('material_id,chapter_no,chapter_title,duration')
            ->order('chapter_no', 'asc')->order('lesson_no', 'asc')
            ->select()->toArray();

        $stat = [];
        foreach ($lessons as $l) {
            $mid = (int) $l['material_id'];
            $no  = (int) $l['chapter_no'];
            $stat[$mid] ??= ['chapters' => [], 'lessons' => 0, 'seconds' => 0];
            if (!isset($stat[$mid]['chapters'][$no])) {
                $stat[$mid]['chapters'][$no] = [
                    'no'      => $no,
                    'title'   => (string) $l['chapter_title'],
                    'lessons' => 0,
                ];
            }
            $stat[$mid]['chapters'][$no]['lessons']++;
            $stat[$mid]['lessons']++;
            $stat[$mid]['seconds'] += (int) $l['duration'];
        }

        $out = [];
        foreach ($rows as $r) {
            $mid = (int) $r['id'];
            $s   = $stat[$mid] ?? ['chapters' => [], 'lessons' => 0, 'seconds' => 0];
            $out[] = [
                'id'        => $mid,
                'title'     => (string) $r['title'],
                'summary'   => mb_substr(trim((string) $r['summary']), 0, 76),
                'cover_url' => !empty($r['cover']) ? $r['cover'] : '/static/img/sample-fallback.svg',
                'chapters'  => array_values($s['chapters']),
                'chapters_all' => count($s['chapters']),
                'lessons'   => (int) $s['lessons'],
                'hours'     => \app\common\service\VideoService::hoursText((int) $s['seconds']),
            ];
        }
        return $out;
    }
}
