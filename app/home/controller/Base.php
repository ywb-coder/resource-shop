<?php
namespace app\home\controller;

use app\common\service\DeliveryService;
use app\common\service\DownloadService;
use app\common\service\SystemConfigService;
use app\home\service\CartService;
use app\home\service\MaterialService;
use think\App;

class Base
{
    protected App $app;
    protected int $userId = 0;
    protected array $loginUser = [];

    public function __construct(App $app)
    {
        $this->app       = $app;
        $this->userId    = (int) session('user_id');
        $this->loginUser = session('user_info') ?? [];
    }

    protected function requireLogin(): void
    {
        if (!$this->userId) {
            if (request()->isAjax()) {
                throw new \think\exception\HttpResponseException(
                    json(['code' => 401, 'msg' => '请先登录'])
                );
            }
            throw new \think\exception\HttpResponseException(redirect('/'));
        }
    }

    protected function success(mixed $data = null, string $msg = 'ok'): \think\Response
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    protected function error(string $msg, int $code = 1): \think\Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => null]);
    }





    /**
     * 返回资料的获取方式。
     *
     * 网盘和直链可以同时配置：
     * - 请求带 way=file 时直接吐文件流（前端点「下载文件」走这条）
     * - 否则返回可用方式的 JSON，只有一种直链时也附上 file_url 让前端自己拉
     * - 卡密商品返回 cards / use_url / use_guide，需要传 $userId
     */
    protected function deliverMaterial(\app\common\model\Material $material, int $userId = 0): \think\Response
    {
        $ways = DeliveryService::ways($material);
        if (!$ways) {
            return $this->error('资料暂未上传，请联系客服');
        }
        // 卡密商品：返回这个用户拿到的卡密和使用地址
        if (in_array(DeliveryService::WAY_CARD, $ways, true)) {
            return $this->success(['ways' => $ways] + \app\common\service\CardKeyService::delivery($material, $userId));
        }

        if (input('get.way', '') === DeliveryService::WAY_FILE) {
            try {
                $realFile = DeliveryService::resolveFile($material);
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage());
            }
            // 真正吐文件流才算一次下载（前面拿交付信息的 JSON 请求也会记，靠 DownloadService 去重）
            DownloadService::record(
                (int) $material->id,
                $userId,
                (float) $material->price > 0 ? DownloadService::TYPE_PAID : DownloadService::TYPE_FREE
            );
            return new \app\common\response\StreamFile($realFile, (string) ($material->file_name ?: ''));
        }

        $data = ['ways' => $ways];
        if (DeliveryService::hasPan($material)) {
            $data['url']  = $material->pan_url;
            $data['code'] = $material->pan_code;
        }
        if (DeliveryService::hasFile($material)) {
            $data['file_name'] = $material->file_name ?: basename($material->file_path);
        }
        return $this->success($data);
    }

    protected function view(string $template, array $vars = []): \think\Response
    {
        $siteConfig = SystemConfigService::getAll();
        $categories = (new MaterialService())->getCategories();
        $vars['siteConfig']    = $siteConfig;
        $vars['loginUser']     = $this->loginUser;
        $vars['categories']    = $categories;
        $vars['searchKw']      = $vars['searchKw'] ?? input('get.q', '');
        $vars['cartCount']     = $this->userId ? (new CartService())->getCount($this->userId) : 0;
        $vars['seo_title']     = $vars['seo_title'] ?? ($siteConfig['site_name'] ?? '');
        $vars['seo_keywords']  = $vars['seo_keywords'] ?? ($siteConfig['site_keywords'] ?? '');
        $vars['seo_description'] = $vars['seo_description'] ?? ($siteConfig['site_description'] ?? '');
        $vars['seo_canonical'] = $vars['seo_canonical'] ?? '';
        // 手机端底部 Tab（同小程序）：当前页属于哪个；有自己吸底栏的页面（详情页）传 hideTabbar 关掉
        $path = trim(request()->pathinfo(), '/');
        $vars['tabActive'] = match (true) {
            $path === '' || $path === 'index'                          => 'home',
            str_starts_with($path, 'list')                             => 'list',
            in_array($path, ['user/center', 'user/orders'], true)      => 'mine',
            str_starts_with($path, 'user') => 'me',
            default                                                    => '',
        };
        $vars['hideTabbar'] = $vars['hideTabbar'] ?? false;
        return view($template, $vars);
    }
}
