<?php
namespace app\home\service;

use app\common\model\Material;
use app\common\model\Category;
use app\common\service\CategoryTree;
use think\db\BaseQuery;

class MaterialService
{
    /**
     * 公开陈列的资料：上架 且 未隐藏。
     *
     * 隐藏资料不进列表/搜索/推荐，但详情页直链照样打得开、买得了——
     * 专供某个渠道或还在试卖的资料用这个，别和下架（status=0）搞混。
     */
    private function visible(): BaseQuery
    {
        return Material::where('status', 1)->where('is_hidden', 0);
    }

    public function getList(array $params, int $page = 1, int $pageSize = 20): array
    {
        $query = $this->visible()->with(['category']);

        if (!empty($params['category_id'])) {
            // 选的是顶级分类时，子分类下的资料也要列出来
            $query->whereIn('category_id', CategoryTree::ids((int) $params['category_id']));
        }
        if (!empty($params['q'])) {
            $this->applyKeyword($query, (string) $params['q']);
        }
        if (!empty($params['free'])) {
            $query->where('price', 0);
        }
        // 资源类型筛选：skill / doc / software / test
        if (!empty($params['res_type'])) {
            $query->where('res_type', (string) $params['res_type']);
        }

        // 排序字段必须先于兜底的 sort/id，否则永远只是同权重时的次级排序
        match ($params['sort'] ?? '') {
            'hot'   => $query->order('sales', 'desc')->order('id', 'desc'),
            'price' => $query->order('price', 'asc')->order('id', 'desc'),
            default => $query->order('sort', 'desc')->order('id', 'desc'),
        };

        $total = (clone $query)->count();
        $list  = $this->cards($query->page($page, $pageSize)->select()->toArray());
        return compact('list', 'total', 'page', 'pageSize');
    }

    /**
     * 关键词搜索。
     * 走 ft_material_search 全文索引（ngram 分词），避免 LIKE '%kw%' 全表扫。
     * ngram 最小 2 字，单字关键词切不出词，退回 LIKE。
     */
    private function applyKeyword($query, string $keyword): void
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return;
        }

        if (mb_strlen($kw) < 2) {
            $like = '%' . $kw . '%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                  ->whereOr('tags', 'like', $like)
                  ->whereOr('summary', 'like', $like);
            });
            return;
        }

        // 用双引号包成短语，既等价于原来的子串匹配，
        // 又能挡掉 + - * ~ ( ) 这些 BOOLEAN MODE 的运算符
        $phrase = '"' . str_replace('"', ' ', $kw) . '"';
        $query->whereRaw('MATCH(title, tags, summary) AGAINST (? IN BOOLEAN MODE)', [$phrase]);
    }

    public function getDetail(int $id): ?array
    {
        $material = Material::with(['category', 'previews'])->where('id', $id)->where('status', 1)->find();
        if (!$material) return null;
        Material::where('id', $id)->setInc('views');
        $data = $material->toArray();
        if (!empty($data['catalog']) && is_string($data['catalog'])) {
            $data['catalog'] = json_decode($data['catalog'], true) ?: [];
        }
        $data['description'] = self::tidyDescription((string) ($data['description'] ?? ''));
        $data['category_parent'] = CategoryTree::parentName((int) $data['category_id']);
        return $data;
    }

    /**
     * 详情介绍展示前清理：去掉空段落，以及下面没有内容的小标题
     * （有几份资料的列表内容缺失，只剩标题挂着，看着像页面坏了）。只影响展示，不改库。
     */
    public static function tidyDescription(string $html): string
    {
        $html = preg_replace('#<p>\s*(?:<br\s*/?>)?\s*</p>#i', '', $html);
        // 标题后面紧跟另一个标题或已到结尾，说明这个标题下面是空的
        // 标题内容不能跨过它自己的结束标签，否则会一路吞到后面的标题
        return preg_replace('#<h([1-6])[^>]*>(?:(?!</h\1>).)*</h\1>\s*(?=<h[1-6]|$)#is', '', $html);
    }

    public function getHot(int $limit = 10): array
    {
        $rows = $this->visible()->with(['category'])->where('is_hot', 1)->order('sales', 'desc')->limit($limit)->select()->toArray();
        // 后台一份都没勾「热门」时按销量取，别让热门板块整块消失
        if (!$rows) {
            $rows = $this->visible()->with(['category'])->order('sales', 'desc')->limit($limit)->select()->toArray();
        }
        return $this->cards($rows);
    }

    public function getRecommend(int $limit = 10): array
    {
        return $this->cards($this->visible()->with(['category'])->where('is_recommend', 1)->order('id', 'desc')->limit($limit)->select()->toArray());
    }

    /** 推荐位：按排序权重取头部内容，首页「刚上架」用这个才不会是清一色的同分类 */
    public function getTop(int $limit = 12): array
    {
        return $this->cards($this->visible()->with(['category'])->order('sort', 'desc')->order('id', 'desc')->limit($limit)->select()->toArray());
    }

    public function getNew(int $limit = 10): array
    {
        return $this->cards($this->visible()->with(['category'])->order('id', 'desc')->limit($limit)->select()->toArray());
    }

    public function getRelated(int $currentId, int $categoryId, int $limit = 5): array
    {
        return $this->cards($this->visible()->with(['category'])
            ->where('category_id', $categoryId)
            ->where('id', '<>', $currentId)
            ->order('sales', 'desc')
            ->limit($limit)
            ->select()->toArray());
    }

    /** 列表数据补上卡片要用的派生字段，网站卡片和小程序卡片共用 */
    private function cards(array $list): array
    {
        foreach ($list as &$item) {
            $item['category_name'] = $item['category']['name'] ?? '';
            $item['chapter_count'] = self::chapterCount($item['catalog'] ?? null);
        }
        return $list;
    }

    /**
     * 目录里的章节数。
     * catalog 存的是 [{level,label,title,page}]，level=1 是篇/卷，level=2 才是章；
     * 问答型资料只有一级目录，这时候就拿一级的数量，不然会显示 0 章。
     */
    public static function chapterCount($catalog): int
    {
        if (is_string($catalog)) {
            $catalog = json_decode($catalog, true);
        }
        if (!is_array($catalog)) {
            return 0;
        }

        $lv1 = $lv2 = 0;
        foreach ($catalog as $item) {
            $level = (int) ($item['level'] ?? 1);
            if ($level === 1)      $lv1++;
            elseif ($level === 2)  $lv2++;
        }
        return $lv2 ?: $lv1;
    }

    /** 各分类下公开资料数，资料库分类条上显示用：[category_id => 数量]，顶级分类含子分类，键 0 是全部 */
    public function categoryCounts(): array
    {
        $counts = array_map('intval', $this->visible()->group('category_id')->column('COUNT(*)', 'category_id'));
        $total  = array_sum($counts);
        foreach ($counts as $id => $n) {
            $top = CategoryTree::topId((int) $id);
            if ($top !== (int) $id) $counts[$top] = ($counts[$top] ?? 0) + $n;
        }
        $counts[0] = $total;
        return $counts;
    }

    /** 各资源类型的公开数量：['skill'=>265,'doc'=>0,...,'all'=>287] */
    public function resTypeCounts(): array
    {
        $counts = array_map('intval', $this->visible()->group('res_type')->column('COUNT(*)', 'res_type'));
        $counts['all'] = array_sum($counts);
        return $counts;
    }

    /**
     * 资料库的两行分类导航：第一行顶级分类，选中的顶级分类有子分类时第二行列子分类。
     * 没有内容的分类不出现——点进去空白页比少个入口更伤体验。
     * @return array{tops:array, children:array, activeTop:int}
     */
    public function categoryNav(int $currentId, ?array $counts = null): array
    {
        $all       = $this->getCategories();
        $counts    = $counts ?? $this->categoryCounts();
        $has       = fn ($id) => (int) ($counts[$id] ?? 0) > 0;
        $activeTop = $currentId ? CategoryTree::topId($currentId) : 0;
        return [
            'tops'      => array_values(array_filter($all, fn ($c) => (int) $c['parent_id'] === 0 && $has($c['id']))),
            'children'  => $activeTop ? array_values(array_filter($all, fn ($c) => (int) $c['parent_id'] === $activeTop && $has($c['id']))) : [],
            'activeTop' => $activeTop,
        ];
    }

    /** 某个顶级分类下有内容的子分类，附数量。列表页侧边栏分组用 */
    public function childrenWithCount(int $topId, ?array $counts = null): array
    {
        $counts = $counts ?? $this->categoryCounts();
        $rows   = [];
        foreach ($this->getCategories() as $c) {
            if ((int) $c['parent_id'] !== $topId) {
                continue;
            }
            $n = (int) ($counts[$c['id']] ?? 0);
            if ($n > 0) {
                $rows[] = ['id' => (int) $c['id'], 'name' => $c['name'], 'count' => $n];
            }
        }
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);
        return $rows;
    }

    public function getCategories(): array
    {
        return Category::where('status', 1)->order('sort', 'asc')->select()->toArray();
    }
}
