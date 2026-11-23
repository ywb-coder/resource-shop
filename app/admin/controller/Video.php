<?php
namespace app\admin\controller;

use app\admin\service\VideoUploadService;

/** 视频课：分片上传接口（供资料表单使用） */
class Video extends Base
{
    private VideoUploadService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new VideoUploadService();
    }

    /** POST /admin/video/init */
    public function init(): \think\Response
    {
        try {
            $this->service->gc(); // 顺手清掉 24 小时前没传完的残片
            $r = $this->service->init(
                (string) input('post.name', ''),
                (int) input('post.size'),
                (int) input('post.chunks')
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return $this->success($r);
    }

    /** POST /admin/video/chunk */
    public function chunk(): \think\Response
    {
        $file = request()->file('file');
        if (!$file) {
            return $this->error('没有收到分片数据');
        }
        try {
            $r = $this->service->saveChunk((string) input('post.upload_id', ''), (int) input('post.index'), $file);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return $this->success($r);
    }

    /** POST /admin/video/finish */
    public function finish(): \think\Response
    {
        try {
            $r = $this->service->finish((string) input('post.upload_id', ''));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        \app\common\service\LogService::record('视频课', '上传', '上传视频：' . $r['path'] . '（' . $r['duration'] . ' 秒）');
        return $this->success($r, '视频已就绪');
    }

    /** POST /admin/video/remove */
    public function remove(): \think\Response
    {
        $path = (string) input('post.path', '');
        if (!$this->service->remove($path)) {
            return $this->error('删除失败：文件不存在或路径不合法');
        }
        \app\common\service\LogService::record('视频课', '删除', '删除视频文件：' . $path);
        return $this->success();
    }

    // ---------- 课程课时管理 ----------

    /** GET /admin/video/course?material_id=  章节树 */
    public function course(): \think\Response
    {
        $materialId = (int) input('get.material_id');
        $material   = \app\common\model\Material::find($materialId);
        if (!$material || !\app\common\service\VideoService::isVideo($material)) {
            return $this->error('请选择一个视频课商品');
        }
        return $this->success([
            'chapters' => \app\common\service\VideoService::chaptersOf($material),
            'stat'     => \app\common\service\VideoService::courseStat($material),
        ]);
    }

    /** POST /admin/video/lesson/save  新增或修改一节 */
    public function lessonSave(): \think\Response
    {
        $id = (int) input('post.id');
        $materialId = (int) input('post.material_id');
        $material = \app\common\model\Material::find($materialId);
        if (!$material || !\app\common\service\VideoService::isVideo($material)) {
            return $this->error('请选择一个视频课商品');
        }
        $title = trim((string) input('post.title', ''));
        if ($title === '') {
            return $this->error('请填写课时标题');
        }
        $videoPath = trim((string) input('post.video_path', ''));
        if ($videoPath !== '' && !str_starts_with($videoPath, 'protected/videos/')) {
            return $this->error('视频路径不合法');
        }

        $data = [
            'material_id'   => $materialId,
            'chapter_no'    => max(1, (int) input('post.chapter_no', 1)),
            'chapter_title' => mb_substr(trim((string) input('post.chapter_title', '')), 0, 100),
            'lesson_no'     => max(1, (int) input('post.lesson_no', 1)),
            'code'          => mb_substr(trim((string) input('post.code', '')), 0, 20),
            'title'         => mb_substr($title, 0, 200),
            'summary'       => mb_substr(trim((string) input('post.summary', '')), 0, 500),
            'video_path'    => $videoPath,
            'duration'      => max(0, (int) input('post.duration', 0)),
            'size'          => max(0, (int) input('post.size', 0)),
            'width'         => max(0, (int) input('post.width', 0)),
            'height'        => max(0, (int) input('post.height', 0)),
            'sort'          => (int) input('post.sort', 0),
            'status'        => (int) input('post.status', 1) === 1 ? 1 : 0,
        ];

        $lesson = $id > 0 ? \app\common\model\VideoLesson::where('material_id', $materialId)->find($id) : null;
        if ($lesson) {
            $lesson->save($data);
            \app\common\service\LogService::record('视频课', '编辑课时', "《{$material->title}》课时「{$title}」");
        } else {
            $data['create_time'] = time();
            \app\common\model\VideoLesson::create($data);
            \app\common\service\LogService::record('视频课', '新增课时', "《{$material->title}》新增课时「{$title}」");
        }
        return $this->success();
    }

    /** POST /admin/video/lesson/delete */
    public function lessonDelete(): \think\Response
    {
        $id = (int) input('post.id');
        $lesson = \app\common\model\VideoLesson::find($id);
        if (!$lesson) {
            return $this->error('课时不存在');
        }
        // 连文件一起删，别在磁盘上攒孤儿
        if ($lesson->video_path) {
            $this->service->remove((string) $lesson->video_path);
        }
        $lesson->delete();
        \app\common\service\LogService::record('视频课', '删除课时', '删除课时 ID ' . $id);
        return $this->success();
    }

    /**
     * POST /admin/video/import  批量导入/更新课时（按 code 对齐）
     * 给批量搬家、重新导入用，重复执行安全。
     */
    public function import(): \think\Response
    {
        $materialId = (int) input('post.material_id');
        $material   = \app\common\model\Material::find($materialId);
        if (!$material || !\app\common\service\VideoService::isVideo($material)) {
            return $this->error('请选择一个视频课商品');
        }
        $rows = (array) input('post.lessons/a', []);
        if (!$rows) {
            return $this->error('没有要导入的课时');
        }

        $added = $updated = 0;
        foreach ($rows as $i => $r) {
            $code = mb_substr(trim((string) ($r['code'] ?? '')), 0, 20);
            if ($code === '') {
                continue;
            }
            $data = [
                'material_id'   => $materialId,
                'chapter_no'    => max(1, (int) ($r['chapter_no'] ?? 1)),
                'chapter_title' => mb_substr(trim((string) ($r['chapter_title'] ?? '')), 0, 100),
                'lesson_no'     => max(1, (int) ($r['lesson_no'] ?? ($i + 1))),
                'code'          => $code,
                'title'         => mb_substr(trim((string) ($r['title'] ?? $code)), 0, 200),
                'summary'       => mb_substr(trim((string) ($r['summary'] ?? '')), 0, 500),
                'video_path'    => trim((string) ($r['video_path'] ?? '')),
                'duration'      => max(0, (int) ($r['duration'] ?? 0)),
                'size'          => max(0, (int) ($r['size'] ?? 0)),
                'width'         => max(0, (int) ($r['width'] ?? 0)),
                'height'        => max(0, (int) ($r['height'] ?? 0)),
                'sort'          => (int) ($r['sort'] ?? $i),
                'status'        => (int) ($r['status'] ?? 1) === 1 ? 1 : 0,
            ];
            if ($data['video_path'] !== '' && !str_starts_with($data['video_path'], 'protected/videos/')) {
                continue;
            }

            $exist = \app\common\model\VideoLesson::where('material_id', $materialId)->where('code', $code)->find();
            if ($exist) {
                // 没传新视频就保留原来的，避免误清空
                if ($data['video_path'] === '') {
                    unset($data['video_path'], $data['duration'], $data['size'], $data['width'], $data['height']);
                }
                $exist->save($data);
                $updated++;
            } else {
                $data['create_time'] = time();
                \app\common\model\VideoLesson::create($data);
                $added++;
            }
        }

        \app\common\service\LogService::record('视频课', '导入课时', "《{$material->title}》导入 {$added} 节，更新 {$updated} 节");
        return $this->success(['added' => $added, 'updated' => $updated]);
    }
}
