<?php
namespace app\admin\controller;

use app\common\model\Material;
use app\common\service\VideoService;
use think\facade\Db;

/** 课程管理：课程列表 / 课时管理 */
class Course extends Base
{
    public function index(): \think\Response
    {
        $materialId = (int) input('get.material_id');

        // 不带课程 ID 就是列表页
        if (!$materialId) {
            return $this->listView();
        }

        $material = Material::find($materialId);
        if (!$material || !VideoService::isVideo($material)) {
            return redirect('/admin/course');
        }

        return $this->view('course/index', [
            'currentMenu' => 'course',
            'pageTitle'   => '课程课时',
            'material'    => $material->toArray(),
        ]);
    }

    /** 所有视频课一行一门，直接看课时/卡密有没有问题 */
    private function listView(): \think\Response
    {
        $courses = Material::where('goods_type', VideoService::GOODS_VIDEO)
            ->order('id', 'desc')
            ->field('id,title,cover,status,is_hidden,sort,create_time')
            ->select()->toArray();

        $ids = array_column($courses, 'id');
        $stats = $cards = [];
        if ($ids) {
            foreach (Db::name('video_lesson')
                         ->whereIn('material_id', $ids)
                         ->field('material_id, COUNT(*) AS lessons, SUM(duration) AS seconds, '
                                 . 'COUNT(DISTINCT chapter_no) AS chapters, '
                                 . "SUM(CASE WHEN video_path = '' THEN 1 ELSE 0 END) AS missing")
                         ->group('material_id')->select()->toArray() as $r) {
                $stats[(int) $r['material_id']] = $r;
            }
            foreach (Db::name('card_key')
                         ->whereIn('material_id', $ids)
                         ->field('material_id, SUM(CASE WHEN status=0 THEN 1 ELSE 0 END) AS unsold, '
                                 . 'SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) AS sold')
                         ->group('material_id')->select()->toArray() as $r) {
                $cards[(int) $r['material_id']] = $r;
            }
        }

        foreach ($courses as &$c) {
            $s = $stats[(int) $c['id']] ?? [];
            $k = $cards[(int) $c['id']] ?? [];
            $sec = (int) ($s['seconds'] ?? 0);
            $h = intdiv($sec, 3600);
            $m = intdiv($sec % 3600, 60);
            $c['lessons']  = (int) ($s['lessons'] ?? 0);
            $c['chapters'] = (int) ($s['chapters'] ?? 0);
            $c['missing']  = (int) ($s['missing'] ?? 0);
            $c['duration'] = $sec > 0 ? ($h > 0 ? "{$h} 小时 {$m} 分" : "{$m} 分钟") : '-';
            $c['unsold']   = (int) ($k['unsold'] ?? 0);
            $c['sold']     = (int) ($k['sold'] ?? 0);
        }
        unset($c);

        return $this->view('course/list', [
            'currentMenu' => 'course',
            'pageTitle'   => '课程管理',
            'courses'     => $courses,
        ]);
    }
}
