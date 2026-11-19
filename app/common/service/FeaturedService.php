<?php
namespace app\common\service;

use app\common\model\Material;
use think\facade\Db;

/**
 * 首页「本周精选」轮播位（5 个）。
 *
 * 两种来源：
 * - manual：后台手动指定
 * - auto：每周一按近 7 天下载量自动挑选
 *
 * 后台可以把某个位置「固定」，自动更新时跳过它，避免手工挑的爆款被机器冲掉。
 */
class FeaturedService
{
    /** 轮播位数量 */
    public const SLOTS = 5;

    /** 自动更新的统计窗口（天） */
    public const WINDOW_DAYS = 7;

    private const TABLE = 'home_featured';

    /** 后台「每周一自动更新」总开关的配置键 */
    public const CFG_AUTO = 'featured_auto';
    /** 上次自动更新的时间戳 */
    public const CFG_LAST_RUN = 'featured_last_run';

    /**
     * 5 个位置，一定是 5 条（空位 material 为 null）。
     * 后台编辑用。
     */
    public function slots(): array
    {
        $rows = Db::name(self::TABLE)->order('position', 'asc')->select()->toArray();
        $byPos = [];
        foreach ($rows as $r) {
            $byPos[(int) $r['position']] = $r;
        }

        $ids = array_filter(array_map(fn ($r) => (int) $r['material_id'], $rows));
        $materials = [];
        if ($ids) {
            foreach (Material::whereIn('id', $ids)->field(self::FIELDS)->select()->toArray() as $m) {
                $materials[(int) $m['id']] = $m;
            }
        }
        $cats = $this->categoryNames(array_column($materials, 'category_id'));

        $out = [];
        for ($pos = 1; $pos <= self::SLOTS; $pos++) {
            $row = $byPos[$pos] ?? null;
            $mid = $row ? (int) $row['material_id'] : 0;
            $m   = $materials[$mid] ?? null;
            $out[] = [
                'position'    => $pos,
                'material_id' => $mid,
                'pinned'      => (int) ($row['pinned'] ?? 0),
                'source'      => (string) ($row['source'] ?? 'auto'),
                'ref_count'   => (int) ($row['ref_count'] ?? 0),
                'update_time' => (int) ($row['update_time'] ?? 0),
                'material'    => $m ? $this->decorate($m, $cats) : null,
            ];
        }
        return $out;
    }

    /**
     * 首页轮播用：只返回「资料还在架、没被隐藏」的位，按位置排好。
     * 位子空着就跳过，不占位。
     */
    public function forHome(): array
    {
        $list = [];
        foreach ($this->slots() as $s) {
            if ($s['material']) {
                $list[] = $s['material'] + [
                    'position' => $s['position'],
                    'pinned'   => $s['pinned'],
                ];
            }
        }
        return $list;
    }

    /**
     * 表里一个都没有时（刚上线还没跑过自动任务），先按站内推荐顺序兜 5 个，
     * 保证首页不出现空白轮播。
     */
    public function bootstrap(int $limit = self::SLOTS): array
    {
        $ids = Material::where('status', 1)
            ->where('is_hidden', 0)
            ->where('category_id', '<>', 33)
            ->order('sort', 'desc')
            ->limit($limit)
            ->column('id');
        if (!$ids) {
            return [];
        }

        $ids = array_map('intval', $ids);
        $materials = [];
        foreach (Material::whereIn('id', $ids)->field(self::FIELDS)->select()->toArray() as $m) {
            $materials[(int) $m['id']] = $m;
        }
        $cats = $this->categoryNames(array_column($materials, 'category_id'));

        $out = [];
        foreach ($ids as $i => $id) {
            if (!isset($materials[$id])) {
                continue;
            }
            $out[] = $this->decorate($materials[$id], $cats) + ['position' => $i + 1, 'pinned' => 0];
        }
        return $out;
    }

    /** 手动指定某个位置放哪份资料 */
    public function setSlot(int $position, int $materialId, ?bool $pinned = null): void
    {
        if ($position < 1 || $position > self::SLOTS) {
            throw new \RuntimeException('位置只能是 1-' . self::SLOTS);
        }
        $material = Material::find($materialId);
        if (!$material) {
            throw new \RuntimeException('资料不存在');
        }
        if ((int) $material->status !== 1 || (int) $material->is_hidden === 1) {
            throw new \RuntimeException('该资料已下架或被隐藏，不能放到首页');
        }

        // 同一份资料不要在两个位置重复出现
        Db::name(self::TABLE)->where('material_id', $materialId)->where('position', '<>', $position)->delete();

        $keep = Db::name(self::TABLE)->where('material_id', $materialId)->value('pinned');
        $pinnedVal = $pinned === null ? (int) ($keep ?? 0) : (int) $pinned;

        $this->upsert($position, $materialId, 'manual', $pinnedVal, 0);
    }

    public function clearSlot(int $position): void
    {
        Db::name(self::TABLE)->where('position', $position)->delete();
    }

    /** 切换固定：固定的位在自动更新时保留 */
    public function togglePin(int $position): int
    {
        $row = Db::name(self::TABLE)->where('position', $position)->find();
        if (!$row) {
            throw new \RuntimeException('该位置还没有内容');
        }
        $next = (int) $row['pinned'] === 1 ? 0 : 1;
        Db::name(self::TABLE)->where('position', $position)->update([
            'pinned'      => $next,
            'update_time' => time(),
        ]);
        return $next;
    }

    /**
     * 按下载量重排 5 个位置。
     *
     * - 固定位（pinned=1）原样保留
     * - 其余位置按近 7 天下载量从高到低填；不够就放宽到 30 天、再不够按站内推荐顺序补
     *
     * @return array{kept:int, picked:int, empty:int, list:array}
     */
    public function refresh(int $days = self::WINDOW_DAYS): array
    {
        $slots = $this->slots();

        $pinned = [];
        foreach ($slots as $s) {
            if ($s['pinned'] === 1 && $s['material']) {
                $pinned[$s['position']] = $s['material_id'];
            }
        }

        $need = self::SLOTS - count($pinned);
        $picked = [];   // material_id => 参考下载量
        $used   = array_values($pinned);

        if ($need > 0) {
            // 7 天没有数据时逐级放宽，避免新站因为没下载量而轮播全空
            foreach ([$days, 30, 0] as $window) {
                if (count($picked) >= $need) {
                    break;
                }
                $batch = DownloadService::ranking($window, $need + 10, array_merge($used, array_keys($picked)));
                foreach ($batch as $r) {
                    if (count($picked) >= $need) {
                        break;
                    }
                    if (isset($picked[$r['material_id']])) {
                        continue;
                    }
                    $picked[$r['material_id']] = $r['n'];
                }
            }

            // 还是不够就用站内推荐顺序补齐，保证 5 个位子都有人
            if (count($picked) < $need) {
                $fallback = Material::where('status', 1)
                    ->where('is_hidden', 0)
                    ->where('category_id', '<>', 33)
                    ->whereNotIn('id', array_merge($used, array_keys($picked)))
                    ->order('sort', 'desc')
                    ->limit($need - count($picked))
                    ->column('id');
                foreach ($fallback as $id) {
                    $picked[(int) $id] = 0;
                }
            }
        }

        // 先清掉非固定位，再按位置顺序写回
        Db::name(self::TABLE)->where('pinned', 0)->delete();

        $queue = $picked;   // material_id => n
        $list  = [];
        for ($pos = 1; $pos <= self::SLOTS; $pos++) {
            if (isset($pinned[$pos])) {
                $list[$pos] = $pinned[$pos];
                continue;
            }
            $mid = array_key_first($queue);
            if ($mid === null) {
                continue;
            }
            $n = $queue[$mid];
            unset($queue[$mid]);
            $this->upsert($pos, (int) $mid, 'auto', 0, (int) $n);
            $list[$pos] = (int) $mid;
        }

        SystemConfigService::set(self::CFG_LAST_RUN, (string) time());

        return [
            'kept'   => count($pinned),
            'picked' => count($picked),
            'empty'  => self::SLOTS - count($list),
            'list'   => $list,
        ];
    }

    /** 自动更新总开关是否打开 */
    public static function autoEnabled(): bool
    {
        return (string) SystemConfigService::get(self::CFG_AUTO, '1') === '1';
    }

    public static function setAuto(bool $on): void
    {
        SystemConfigService::set(self::CFG_AUTO, $on ? '1' : '0');
    }

    public static function lastRun(): int
    {
        return (int) SystemConfigService::get(self::CFG_LAST_RUN, 0);
    }

    /**
     * 后台展示用的下载排行（带标题、封面、近 7 天/累计两个数）。
     */
    public function rankingCards(int $days = self::WINDOW_DAYS, int $limit = 10): array
    {
        $rows = DownloadService::ranking($days, $limit);
        if (!$rows) {
            return [];
        }

        $ids = array_column($rows, 'material_id');
        $materials = [];
        foreach (Material::whereIn('id', $ids)->field('id,title,cover,category_id')->select()->toArray() as $m) {
            $materials[(int) $m['id']] = $m;
        }
        $cats   = $this->categoryNames(array_column($materials, 'category_id'));
        $totals = DownloadService::totals($ids);

        $out = [];
        foreach ($rows as $i => $r) {
            $m = $materials[$r['material_id']] ?? null;
            if (!$m) {
                continue;
            }
            $out[] = [
                'rank'       => $i + 1,
                'id'         => $r['material_id'],
                'title'      => (string) $m['title'],
                'cover'      => (string) ($m['cover'] ?: ''),
                'category'   => $cats[(int) $m['category_id']] ?? '技能',
                'week'       => $r['n'],
                'total'      => $totals[$r['material_id']] ?? 0,
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- 内部

    private const FIELDS = 'id, title, cover, summary, price, file_format, category_id, file_size';

    private function upsert(int $position, int $materialId, string $source, int $pinned, int $refCount): void
    {
        $data = [
            'position'    => $position,
            'material_id' => $materialId,
            'source'      => $source,
            'pinned'      => $pinned,
            'ref_count'   => $refCount,
            'update_time' => time(),
        ];
        $exists = Db::name(self::TABLE)->where('position', $position)->find();
        if ($exists) {
            Db::name(self::TABLE)->where('id', $exists['id'])->update($data);
        } else {
            Db::name(self::TABLE)->insert($data);
        }
    }

    private function decorate(array $m, array $cats): array
    {
        return [
            'id'            => (int) $m['id'],
            'title'         => (string) $m['title'],
            'cover'         => (string) ($m['cover'] ?: ''),
            'summary'       => (string) ($m['summary'] ?: ''),
            'price'         => (float) $m['price'],
            'file_format'   => (string) ($m['file_format'] ?: ''),
            'file_size'     => (int) ($m['file_size'] ?? 0),
            'category_name' => $cats[(int) $m['category_id']] ?? '技能',
        ];
    }

    private function categoryNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        return \app\common\model\Category::whereIn('id', $ids)->column('name', 'id');
    }
}
