<?php
namespace app\common\service;

use app\common\model\CardKey;
use app\common\model\Material;
use app\common\model\VideoGrant;
use think\facade\Db;

/**
 * 视频课：卡密兑换 → 观看授权 → 流式播放 / 限次下载。
 *
 * 设计要点：
 * 1) 视频文件放 protected/videos/（web 根之外），外网没有任何直链；
 * 2) 播放不经过 PHP 吐字节：鉴权通过后回一个 X-Accel-Redirect，让 nginx 用 sendfile
 *    直接发，Range(拖进度)、倍速、手机续播全靠 nginx，PHP 只承担一次鉴权的开销；
 * 3) 一张卡密只兑换一次，兑换结果落在 shop_video_grant，之后不再核对卡密。
 */
class VideoService
{
    /** 商品类型：视频课（1=资料，2=卡密商品） */
    public const GOODS_VIDEO = 3;

    /** 视频存放目录（相对项目根） */
    public const DIR = 'protected/videos';

    /** nginx internal 前缀，必须在站点配置里用 alias 指到 DIR 的真实路径 */
    public const ACCEL_PREFIX = '/__vv/';

    /** 授权 Cookie 有效期（秒），0 = 跟随浏览器会话 */
    public const COOKIE_DAYS = 365;

    public static function isVideo($material): bool
    {
        return $material instanceof Material && (int) $material->goods_type === self::GOODS_VIDEO;
    }

    /** 旧版设备授权 Cookie 名（已停用，仅排障参考） */
    public static function cookieName(int $materialId): string
    {
        return 'vg_' . $materialId;
    }

    /** 视频绝对路径；文件不存在或路径非法返回空串 */
    public static function absPath(Material $material): string
    {
        return self::absPathOf((string) $material->video_path);
    }

    /** 同上，但直接吃相对路径（课时用） */
    public static function absPathOf(string $rel): string
    {
        $rel = trim($rel);
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }
        if (!str_starts_with($rel, self::DIR . '/')) {
            return '';
        }
        $abs  = root_path() . $rel;
        $real = realpath($abs);
        $base = realpath(root_path() . self::DIR);
        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            return '';
        }
        return is_file($real) ? $real : '';
    }

    /** 给 nginx 的 internal 路径，如 /__vv/course/WB-01-01.mp4 */
    public static function accelPath(Material $material): string
    {
        return self::accelPathOf((string) $material->video_path);
    }

    public static function accelPathOf(string $rel): string
    {
        $rel = trim($rel);
        if (!str_starts_with($rel, self::DIR . '/')) {
            return '';
        }
        return self::ACCEL_PREFIX . substr($rel, strlen(self::DIR) + 1);
    }

    // ---------- 多课时课程 ----------

    /** 这门课是不是多课时课程（有课时记录就算） */
    public static function isCourse(Material $material): bool
    {
        return self::isVideo($material) && \app\common\model\VideoLesson::where('material_id', $material->id)->count() > 0;
    }

    /**
     * 按章节分组返回课时树：[[no, title, lessons=>[lesson,...]], ...]
     * 播放页左侧目录直接用它渲染，避免前端再拼一次。
     */
    public static function chaptersOf(Material $material): array
    {
        $rows = \app\common\model\VideoLesson::where('material_id', $material->id)
            ->where('status', 1)
            ->order('chapter_no', 'asc')->order('lesson_no', 'asc')->order('id', 'asc')
            ->select()->toArray();

        $chapters = [];
        foreach ($rows as $r) {
            $no = (int) $r['chapter_no'];
            if (!isset($chapters[$no])) {
                $chapters[$no] = ['no' => $no, 'title' => $r['chapter_title'] ?: ('第 ' . $no . ' 章'), 'lessons' => []];
            }
            $r['duration_text'] = self::durationText((int) $r['duration']);
            $r['quality']       = self::qualityLabel((int) $r['width'], (int) $r['height']);
            $chapters[$no]['lessons'][] = $r;
        }
        return array_values($chapters);
    }

    /** 取一个课时，且必须属于这门课 */
    public static function lessonOf(Material $material, int $lessonId): ?\app\common\model\VideoLesson
    {
        if ($lessonId <= 0) {
            return null;
        }
        $lesson = \app\common\model\VideoLesson::where('id', $lessonId)
            ->where('material_id', $material->id)->where('status', 1)->find();
        return $lesson ?: null;
    }

    /** 第一个课时（带 l 参数进课程页时默认播它） */
    public static function firstLesson(Material $material): ?\app\common\model\VideoLesson
    {
        $l = \app\common\model\VideoLesson::where('material_id', $material->id)->where('status', 1)
            ->order('chapter_no', 'asc')->order('lesson_no', 'asc')->order('id', 'asc')->find();
        return $l ?: null;
    }

    /** 课程总时长（秒）+ 课时数 */
    public static function courseStat(Material $material): array
    {
        $rows = \app\common\model\VideoLesson::where('material_id', $material->id)->where('status', 1)
            ->field('duration, chapter_no')->select()->toArray();
        $seconds = 0;
        $chapters = [];
        foreach ($rows as $r) {
            $seconds += (int) $r['duration'];
            $chapters[(int) $r['chapter_no']] = true;
        }
        return ['seconds' => $seconds, 'lessons' => count($rows), 'chapters' => count($chapters)];
    }

    /**
     * 当前访问者在这份视频上的授权。
     *
     * 只按 user_id 认（换手机、换浏览器登录同一账号都能看）；未登录一律无授权。
     */
    public static function grantFor(Material $material, int $userId): ?VideoGrant
    {
        // 权益只认账号：换设备/换浏览器登录同一账号即可看。
        // 旧版曾用 Cookie token 做设备兜底，退出登录后 cookie 仍留在浏览器，
        // 会让新账号/游客误以为已解锁，已彻底移除。
        if ($userId <= 0) {
            return null;
        }
        return VideoGrant::where('material_id', $material->id)
            ->where('user_id', $userId)
            ->order('id', 'desc')
            ->find();
    }

    /** 允许下载？授权有效 + 商品开了下载开关 + 未超出次数 */
    public static function downloadState(Material $material, ?VideoGrant $grant): array
    {
        if (!$grant) {
            return ['allowed' => false, 'reason' => '请先输入卡密解锁'];
        }
        if ((int) $material->video_download !== 1) {
            return ['allowed' => false, 'reason' => '该视频仅支持在线观看'];
        }
        $limit = (int) $material->video_dl_limit;
        $used  = (int) $grant->download_count;
        if ($limit > 0 && $used >= $limit) {
            return ['allowed' => false, 'reason' => '下载次数已用完（上限 ' . $limit . ' 次）', 'used' => $used, 'limit' => $limit];
        }
        return ['allowed' => true, 'used' => $used, 'limit' => $limit, 'remain' => $limit > 0 ? $limit - $used : -1];
    }

    /**
     * 用卡密兑换观看权限。
     *
     * 卡密内容允许三种写法：单码 / 「卡号 密码」 / 「卡号----密码」。
     * 若卡密库里这张卡本身带密码，就必须连密码一起输对。
     *
     * @return array{grant: VideoGrant, card_no: string}
     */
    public function unlock(int $materialId, string $input, int $userId, string $ip, string $ua): array
    {
        $material = Material::find($materialId);
        if (!$material || !self::isVideo($material)) {
            throw new \RuntimeException('该视频不存在');
        }
        if (self::isCourse($material)) {
            // 多课时课程：课时齐不齐由后台管，这里只确认至少有一节能播
            if (!self::firstLesson($material)) {
                throw new \RuntimeException('课程还在上架中，请稍后再来');
            }
        } elseif (self::absPath($material) === '') {
            throw new \RuntimeException('视频文件缺失，请联系客服');
        }

        $input = trim($input);
        if ($input === '') {
            throw new \RuntimeException('请输入卡密');
        }
        // 统一全角空格、去掉多余空白
        $input = str_replace(["\xe3\x80\x80", "\t"], ' ', $input);
        $parts = preg_split('/\s*(?:----|—|,|，|\s)\s*/u', $input, 2);
        $cardNo = trim((string) ($parts[0] ?? ''));
        $secret = trim((string) ($parts[1] ?? ''));
        if ($cardNo === '') {
            throw new \RuntimeException('请输入卡密');
        }

        $card = CardKey::where('material_id', $materialId)->where('card_no', $cardNo)->find();
        if (!$card) {
            throw new \RuntimeException('卡密不存在，请核对后重试');
        }
        $storedSecret = (string) $card->card_secret;
        if ($storedSecret !== '' && !hash_equals($storedSecret, $secret)) {
            throw new \RuntimeException('卡号或密码不正确，请按「卡号 密码」一起输入');
        }

        // 卡密只是一次性兑换凭证：权益归账号，卡密换完即作废。
        // 所以必须先有账号，否则「已经买过」这件事无处安放。
        if ($userId <= 0) {
            throw new \RuntimeException('请先登录，再兑换卡密');
        }

        $existing = VideoGrant::where('card_id', $card->id)->find();
        if ($existing) {
            if ((int) $existing->user_id === $userId) {
                // 卡密已作废，但权益本来就在他账号里，直接进
                self::bindDevice($existing, $material, $ip, $ua);
                return ['grant' => $existing, 'card_no' => $cardNo, 'already' => true];
            }
            throw new \RuntimeException('这张卡密已经被使用了，如果你买过，请用购买时的账号登录');
        }

        if ((int) $card->status === 1) {
            // 已核销却找不到授权（历史数据/异常），一律不再放行
            throw new \RuntimeException('这张卡密已经被使用了');
        }

        $result = Db::transaction(function () use ($material, $card, $cardNo, $userId, $ip, $ua) {
            // 行锁：两个浏览器同时提交同一张卡，只有一个能建出授权
            $locked = CardKey::where('id', $card->id)->lock(true)->find();
            if (!$locked) {
                throw new \RuntimeException('卡密状态异常，请重试');
            }
            $again = VideoGrant::where('card_id', $locked->id)->find();
            if ($again) {
                if ((int) $again->user_id === $userId) {
                    return ['grant' => $again, 'card_no' => $cardNo, 'already' => true];
                }
                throw new \RuntimeException('这张卡密已经被使用了');
            }
            if ((int) $locked->status === 1) {
                throw new \RuntimeException('这张卡密已经被使用了');
            }

            $locked->save([
                'status'    => 1,
                'user_id'   => $userId,
                'sold_time' => time(),
            ]);

            $grant = VideoGrant::create([
                'material_id' => (int) $material->id,
                'card_id'     => (int) $locked->id,
                'card_no'     => $cardNo,
                'user_id'     => $userId,
                'token'       => bin2hex(random_bytes(16)),
                'ip'          => mb_substr($ip, 0, 60),
                'ua'          => mb_substr($ua, 0, 250),
                'devices'     => json_encode([self::fingerprint($ua, $ip)]),
                'create_time' => time(),
                'last_time'   => time(),
            ]);

            LogService::record('视频课', '卡密兑换', "视频《{$material->title}》用卡密 {$cardNo} 兑换成功，卡密已作废");

            return ['grant' => $grant, 'card_no' => $cardNo, 'already' => false];
        });

        // 真正兑换成功（不是“本来就在你账号里”）才推飞书，重复输入不再打扰群
        if (empty($result['already'])) {
            NotifyService::cardUnlocked([
                'title'   => (string) $material->title,
                'card_no' => (string) ($result['card_no'] ?? $cardNo),
                'user_id' => $userId,
                'ip'      => (string) $ip,
            ]);
        }

        return $result;
    }

    /**
     * 某个账号已解锁的课程（我的课程）。
     * 以授权表为准，和订单无关——卡密课本来就不走站内下单。
     */
    public static function coursesOfUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $grants = VideoGrant::where('user_id', $userId)->order('last_time', 'desc')->order('id', 'desc')->select()->toArray();
        if (!$grants) {
            return [];
        }
        $ids = array_unique(array_column($grants, 'material_id'));
        $rows = Material::whereIn('id', $ids)->select()->toArray();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $out = [];
        foreach ($grants as $g) {
            $m = $byId[(int) $g['material_id']] ?? null;
            if (!$m) {
                continue;
            }
            $stat = self::courseStat(new Material($m));
            $first = self::firstLesson(new Material($m));
            $out[] = [
                'material_id' => (int) $m['id'],
                'title'       => $m['title'],
                'cover'       => $m['cover'],
                'summary'     => $m['summary'],
                'chapters'    => $stat['chapters'],
                'lessons'     => $stat['lessons'],
                'seconds'     => $stat['seconds'],
                'duration'    => self::durationText($stat['seconds']),
                'duration_text' => self::hoursText($stat['seconds']),
                'first_lesson' => $first ? (int) $first->id : 0,
                'last_time'   => self::ts($g['last_time']),
                'unlock_time' => self::ts($g['create_time']),
                // 模板里不做 date() 运算，0 值给空串，避免出现 1970-01-01
                'unlock_text' => self::ts($g['create_time']) > 0 ? date('Y-m-d', self::ts($g['create_time'])) : '',
                'last_text'   => self::ts($g['last_time']) > 0 ? date('Y-m-d H:i', self::ts($g['last_time'])) : '',
                'status'      => (int) $m['status'],
            ];
        }
        return $out;
    }

    /**
     * 统一成时间戳。
     * 模型对 create_time 做了自动时间戳处理，读出来可能是 'Y-m-d H:i:s' 字符串，
     * 直接 (int) 强转会只剩年份（1970-01-01 就是这么来的）。
     */
    public static function ts($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        $t = strtotime((string) $value);
        return $t === false ? 0 : $t;
    }

    /** 清掉旧版设备授权 Cookie（vg_*）：授权只认账号，cookie 不再参与鉴权 */
    public static function clearDeviceCookies(): void
    {
        foreach (array_keys($_COOKIE) as $name) {
            $name = (string) $name;
            if (!str_starts_with($name, 'vg_')) {
                continue;
            }
            setcookie($name, '', [
                'expires'  => time() - 86400,
                'path'     => '/',
                'secure'   => (bool) env('COOKIE_SECURE', false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /** 记一次下载，返回累计次数 */
    public static function countDownload(VideoGrant $grant): int
    {
        VideoGrant::where('id', $grant->id)->inc('download_count')->update(['last_time' => time()]);
        return (int) $grant->download_count + 1;
    }

    /** 刷新最近观看时间（后台看谁在看） */
    public static function touch(VideoGrant $grant): void
    {
        VideoGrant::where('id', $grant->id)->update(['last_time' => time()]);
    }

    /**
     * 设备指纹：UA + IP 网段。
     *
     * 只取 IP 前三段，是因为手机 4G/5G 在同一城市换基站时末段经常变，
     * 用完整 IP 会把同一个人当成新设备，白白吃掉名额。
     */
    public static function fingerprint(string $ua, string $ip): string
    {
        $seg = $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            $seg = $p[0] . '.' . $p[1] . '.' . $p[2];
        } elseif (str_contains($ip, ':')) {
            $seg = implode(':', array_slice(explode(':', $ip), 0, 4)); // IPv6 /64
        }
        return substr(md5(mb_substr($ua, 0, 200) . '|' . $seg), 0, 16);
    }

    /**
     * 把当前设备登记进授权。
     *
     * video_dev_limit = 0 时什么都不做（默认，不打扰用户）；
     * 设了上限就按台数算，满了明确告诉他去联系客服，而不是莫名其妙看不到。
     */
    private static function bindDevice(VideoGrant $grant, Material $material, string $ip, string $ua): void
    {
        $limit = (int) $material->video_dev_limit;
        if ($limit <= 0) {
            return;
        }
        $fp      = self::fingerprint($ua, $ip);
        $devices = json_decode((string) $grant->devices, true);
        if (!is_array($devices)) {
            $devices = [];
        }
        if (in_array($fp, $devices, true)) {
            return;
        }
        if (count($devices) >= $limit) {
            throw new \RuntimeException(
                '这张卡密已在 ' . count($devices) . ' 台设备上使用（上限 ' . $limit . ' 台）。换设备请联系客服解绑后再试。'
            );
        }
        $devices[] = $fp;
        // 只留最近 20 个，防止有人靠反复清缓存把字段撑大
        $grant->save(['devices' => json_encode(array_slice($devices, -20))]);
    }

    /** 后台手动解绑：清空这张卡密绑过的设备，让买家能在新设备上重新兑换 */
    public static function unbindCard(int $cardId): bool
    {
        return (bool) VideoGrant::where('card_id', $cardId)->update(['devices' => json_encode([])]);
    }

    /** 某张卡密已绑定的设备数（后台列表展示用） */
    public static function deviceCountOfCard(int $cardId): int
    {
        $g = VideoGrant::where('card_id', $cardId)->field('devices')->find();
        if (!$g) {
            return 0;
        }
        $d = json_decode((string) $g->devices, true);
        return is_array($d) ? count($d) : 0;
    }

    /** 时长格式化成 mm:ss / h:mm:ss */
    public static function durationText(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }

    /** 把秒数说成人话：5 小时 49 分 */
    public static function hoursText(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return $h > 0 ? sprintf('%d 小时 %d 分', $h, $m) : sprintf('%d 分钟', $m);
    }

    /** 清晰度标签：1280×720 → 720P */
    public static function qualityLabel(int $width, int $height): string
    {
        $short = min($width, $height);
        return $short > 0 ? $short . 'P' : '';
    }
}
