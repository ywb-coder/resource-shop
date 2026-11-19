<?php
namespace app\home\controller;

use app\common\service\FreebieDeliveryService;
use app\common\response\StreamFile;
use app\common\service\FreebieClaimService;

/** 网站版免费资料专区（规则和小程序同一套：FreebieClaimService） */
class Free extends Base
{
    /** GET /free */
    public function index(): \think\Response
    {
        // 站内所有资源本来就免费，领取专区没有内容时直接把人送回资源列表，不留空页面
        $list = FreebieClaimService::publicList();
        if (!$list) {
            return redirect('/list');
        }
        return $this->view('free/index', [
            'list'       => $list,
            'claimedIds' => FreebieClaimService::claimedIds($this->userId),
            'seo_title'  => '免费资料 - ' . \app\common\service\SystemConfigService::get('site_name', '知集科技'),
        ]);
    }

    /** POST /free/claim  领取（领过的再调一次就是查看），返回发放内容 */
    public function claim(): \think\Response
    {
        $this->requireLogin();
        try {
            $delivery = (new FreebieDeliveryService())->claim((int) input('post.id'), $this->userId);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        // 小程序那边的下载地址要 Bearer token，网页用会话版的
        if (!empty($delivery['file_url'])) {
            $delivery['file_url'] = '/free/file?id=' . (int) input('post.id');
        }
        return $this->success($delivery, '领取成功');
    }

    /** GET /free/file?id=  领过才能下载 */
    public function file(): \think\Response
    {
        $this->requireLogin();
        try {
            [$path, $name] = (new FreebieDeliveryService())->file((int) input('get.id'), $this->userId);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return new StreamFile($path, $name);
    }
}
