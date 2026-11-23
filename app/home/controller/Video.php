<?php
namespace app\home\controller;

use app\common\model\Material;
use app\common\model\VideoLesson;
use app\common\service\DownloadService;
use app\common\service\VideoService;

/**
 * 视频课：单视频播放页 / 多课时课程页 / 卡密解锁 / 流式播放 / 限次下载。
 *
 * 视频字节一律不经 PHP：鉴权通过后返回 X-Accel-Redirect，由 nginx 直接发文件，
 * 这样拖动进度条、倍速、手机端续播都是 nginx 原生能力，也不占 PHP-FPM 进程。
 */
class Video extends Base
{
    public function index(): \think\Response
    {
        $id       = (int) input('param.id');
        $material = Material::find($id);
        if (!$material || (int) $material->status !== 1 || (int) $material->is_hidden === 1) {
            return response('视频不存在', 404);
        }
        if (!VideoService::isVideo($material)) {
            return redirect('/detail/' . $id);
        }

        $grant    = VideoService::grantFor($material, $this->userId);
        $download = VideoService::downloadState($material, $grant);
        $isCourse = VideoService::isCourse($material);

        $vars = [
            'material' => $material->toArray(),
            'unlocked' => (bool) $grant,
            'isLogged' => $this->userId > 0,
            'user_id'  => (int) $this->userId,
            'download' => $download,
            'seo_title'    => ($material->seo_title ?: $material->title),
            'seo_keywords' => $material->seo_keywords ?: $material->tags,
            'seo_description' => $material->seo_description ?: $material->summary,
            'seo_canonical'   => request()->domain() . '/video/' . $id,
            'hideTabbar'   => true,
        ];

        if ($isCourse) {
            $current  = VideoService::lessonOf($material, (int) input('get.l'));
            if (!$current) {
                $current = VideoService::firstLesson($material);
            }
            $curArr = $current ? $current->toArray() : null;
            if ($curArr) {
                // 目录树里的课时带了这两个展示字段，当前课时单独取出来也要补上
                $curArr['duration_text'] = VideoService::durationText((int) $curArr['duration']);
                $curArr['quality']       = VideoService::qualityLabel((int) $curArr['width'], (int) $curArr['height']);
            }
            $stat = VideoService::courseStat($material);
            $vars += [
                'chapters'     => VideoService::chaptersOf($material),
                'current'      => $curArr,
                'stat'         => $stat,
                'total_text'   => self::hoursText($stat['seconds']),
            ];
            return $this->view('video/course', $vars);
        }

        $vars += [
            'duration' => VideoService::durationText((int) $material->video_duration),
            'quality'  => VideoService::qualityLabel((int) $material->video_width, (int) $material->video_height),
        ];
        return $this->view('video/play', $vars);
    }

    /** 把秒数说成人话：8 小时 42 分 */
    private static function hoursText(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return $h > 0 ? sprintf('%d 小时 %d 分', $h, $m) : sprintf('%d 分钟', $m);
    }

    /** POST /video/unlock  卡密兑换（一次性，兑换后权益归账号） */
    public function unlock(): \think\Response
    {
        // 卡密换完就作废，权益必须落在账号上，否则"买过了"这件事无处记录
        if (!$this->userId) {
            return json(['code' => 401, 'msg' => '请先登录，再兑换卡密', 'data' => null]);
        }

        $id   = (int) input('post.id');
        $code = (string) input('post.code', '');

        try {
            $r = (new VideoService())->unlock(
                $id,
                $code,
                $this->userId,
                (string) request()->ip(),
                (string) request()->header('user-agent', '')
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        VideoService::remember($id, (string) $r['grant']->token);

        $material = Material::find($id);
        $download = VideoService::downloadState($material, $r['grant']);

        return $this->success([
            'card_no'  => $r['card_no'],
            'download' => $download,
            'already'  => (bool) ($r['already'] ?? false),
        ], !empty($r['already']) ? '这门课已经在你的账号里了' : '解锁成功');
    }

    /**
     * GET /video/stream?id=&l=
     * 带 l 就是课程里的某一节，不带 l 是单视频课。
     */
    public function stream(): \think\Response
    {
        $id       = (int) input('get.id');
        $material = Material::find($id);
        if (!$material || !VideoService::isVideo($material)) {
            return response('视频不存在', 404);
        }

        $grant = VideoService::grantFor($material, $this->userId);
        if (!$grant) {
            return response('请先输入卡密解锁', 403);
        }

        $rel = (string) $material->video_path;
        $lessonId = (int) input('get.l');
        if ($lessonId > 0) {
            $lesson = VideoService::lessonOf($material, $lessonId);
            if (!$lesson) {
                return response('课时不存在', 404);
            }
            $rel = (string) $lesson->video_path;
        }

        $abs   = VideoService::absPathOf($rel);
        $accel = VideoService::accelPathOf($rel);
        if ($abs === '' || $accel === '') {
            return response('视频文件缺失', 404);
        }

        VideoService::touch($grant);

        return response('', 200, [
            'X-Accel-Redirect'    => $accel,
            'Content-Type'        => 'video/mp4',
            'Content-Disposition' => 'inline',
            'Cache-Control'       => 'private, no-store, max-age=0',
        ]);
    }

    /** GET /video/download?id=&l=  限次下载（次数按整门课算） */
    public function download(): \think\Response
    {
        $id       = (int) input('get.id');
        $material = Material::find($id);
        if (!$material || !VideoService::isVideo($material)) {
            return response('视频不存在', 404);
        }

        $grant = VideoService::grantFor($material, $this->userId);
        $state = VideoService::downloadState($material, $grant);
        if ($state['allowed'] !== true) {
            return response($state['reason'], 403);
        }

        $rel  = (string) $material->video_path;
        $name = $material->title ?: 'video';
        $lessonId = (int) input('get.l');
        if ($lessonId > 0) {
            $lesson = VideoService::lessonOf($material, $lessonId);
            if (!$lesson) {
                return response('课时不存在', 404);
            }
            $rel  = (string) $lesson->video_path;
            $name = ($lesson->code ? $lesson->code . ' ' : '') . $lesson->title;
        }

        $abs   = VideoService::absPathOf($rel);
        $accel = VideoService::accelPathOf($rel);
        if ($abs === '' || $accel === '') {
            return response('视频文件缺失', 404);
        }

        // 先计数再发文件：宁可少给一次，也不让并发请求多拿
        VideoService::countDownload($grant);

        $encoded = rawurlencode($name . '.mp4');
        DownloadService::record(
            (int) $material->id,
            $this->userId,
            DownloadService::TYPE_PAID
        );

        return response('', 200, [
            'X-Accel-Redirect'    => $accel,
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $encoded . '"; filename*=UTF-8\'\'' . $encoded,
            'Cache-Control'       => 'private, no-store, max-age=0',
        ]);
    }
}
