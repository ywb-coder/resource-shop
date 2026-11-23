<?php
namespace app\common\model;

use think\Model;

/** 课程课时：一门课下的每一节，视频文件各自独立 */
class VideoLesson extends Model
{
    protected $name = 'video_lesson';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public function material(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }
}
