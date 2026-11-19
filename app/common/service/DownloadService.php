<?php
namespace app\common\service;

use app\common\model\DownloadLog;
use think\facade\Db;

/**
 * 下载统计。
 *
 * 一次「点下载」在前端会打两个请求：先拿交付信息（JSON），拿到后再请求 way=file 拉文件流。
 * 两个都记会翻倍，所以按「用户 + 资料」做短时间去重，一次下载只算一次。
 *
 * 记录写在 shop_download_log，首页「本周精选」的每周自动更新就是按这张表算的。
 */
class DownloadService
{
    /** 下单购买后下载 */
    public const TYPE_PAID = 1;
    /** 免费资源直接领取下载 */
    public const TYPE_FREE = 2;

    /** 去重窗口（秒）：同一用户 60 秒内重复下载同一份只记一次 */
    public const DEDUPE_SECONDS = 60;

    /**
     * 记一次下载。
     *
     * @return bool 真正写入返回 true，被去重挡掉返回 false
     */
    public static function record(int $materialId, int $userId = 0, int $type = self::TYPE_FREE, int $orderId = 0): bool
    {
        if ($materialId <= 0) {
            return false;
        }

        // 60 秒内同一用户下过同一份，视为同一次下载（刷新、JSON + 文件流双请求都走这里）
        if ($userId > 0) {
            $dupe = DownloadLog::where('material_id', $materialId)
                ->where('user_id', $userId)
                ->where('create_time', '>=', time() - self::DEDUPE_SECONDS)
                ->count();
            if ($dupe > 0) {
                return false;
            }
        }

        DownloadLog::create([
            'user_id'     => $userId,
            'order_id'    => $orderId,
            'material_id' => $materialId,
            'type'        => $type,
            'ip'          => (string) request()->ip(),
            'create_time' => time(),
        ]);

        return true;
    }

    /**
     * 下载排行。
     *
     * @param int $days 统计窗口天数，0 表示全部
     * @param int $limit 取前多少条
     * @param int[] $exclude 需要排除的资料 id
     * @return array<int, array{material_id:int, n:int}>
     */
    public static function ranking(int $days = 7, int $limit = 30, array $exclude = []): array
    {
        $query = Db::name('download_log')->alias('d')
            ->join('material m', 'm.id = d.material_id')
            ->where('m.status', 1)
            ->where('m.is_hidden', 0);

        if ($days > 0) {
            $query->where('d.create_time', '>=', time() - $days * 86400);
        }
        if ($exclude) {
            $query->whereNotIn('d.material_id', $exclude);
        }

        $rows = $query->group('d.material_id')
            ->order('n', 'desc')
            ->order('d.material_id', 'desc')
            ->limit($limit)
            ->field('d.material_id, COUNT(*) AS n')
            ->select()
            ->toArray();

        return array_map(fn ($r) => [
            'material_id' => (int) $r['material_id'],
            'n'           => (int) $r['n'],
        ], $rows);
    }

    /**
     * 批量取下载量，后台列表用。
     *
     * @return array{material_id:int} 每个 id 的累计下载数，没下载过的不在数组里
     */
    public static function totals(array $materialIds): array
    {
        if (!$materialIds) {
            return [];
        }

        $rows = Db::name('download_log')
            ->whereIn('material_id', $materialIds)
            ->group('material_id')
            ->field('material_id, COUNT(*) AS n')
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['material_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** 某份资料的下载明细（后台看最近谁下过） */
    public static function detail(int $materialId, int $limit = 20): array
    {
        return DownloadLog::alias('d')
            ->leftJoin('user u', 'u.id = d.user_id')
            ->where('d.material_id', $materialId)
            ->order('d.id', 'desc')
            ->limit($limit)
            ->field('d.id, d.user_id, d.type, d.ip, d.create_time, u.nickname')
            ->select()
            ->toArray();
    }
}
