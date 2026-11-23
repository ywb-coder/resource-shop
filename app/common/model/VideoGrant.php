<?php
namespace app\common\model;

use think\Model;

/**
 * 视频观看授权：一张卡密兑换出来的「观看/下载」凭证。
 *
 * 一张卡密只能兑换一次（uk_card 唯一索引兜底），兑换后绑定 token 与 user_id，
 * 之后播放、下载都靠这张记录鉴权，不再重复核对卡密。
 */
class VideoGrant extends Model
{
    protected $name = 'video_grant';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public function material(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    public function card(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(CardKey::class, 'card_id');
    }
}
