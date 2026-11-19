<?php
namespace app\admin\controller;

use app\common\model\PaymentConfig;
use app\common\service\FeishuNotifyService;
use app\common\service\ImageOptimizeService;
use app\common\service\LogService;
use app\common\service\SystemConfigService;
use app\common\service\WecomNotifyService;

class Config extends Base
{
    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->checkLogin();
    }

    public function site(): \think\Response
    {
        $config = SystemConfigService::getAll();
        return $this->view('config/site', compact('config') + ['currentMenu' => 'config_site', 'pageTitle' => '网站配置']);
    }

    public function saveSite(): \think\Response
    {
        $data = input('post.');
        unset($data['__token__']);

        $allowKeys = [
            'site_name', 'site_logo', 'site_favicon', 'site_keywords',
            'site_description', 'site_icp', 'site_footer',
            'default_wechat', 'default_wechat_qr', 'watermark_text', 'watermark_opacity',
            'wecom_webhook', 'wecom_notify_pending', 'wecom_notify_paid',
            'feishu_webhook', 'feishu_secret', 'feishu_notify_order', 'feishu_notify_paid',
            'feishu_notify_free', 'feishu_notify_user', 'feishu_notify_comment',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_name',
        ];

        $save = [];
        foreach ($allowKeys as $key) {
            if (array_key_exists($key, $data)) {
                $save[$key] = $data[$key];
            }
        }

        SystemConfigService::batchSet($save);
        LogService::record('网站配置', '保存', '保存网站配置');
        return $this->success();
    }

    /** POST /admin/config/notify-test  给某个渠道发一条测试消息 */
    public function testNotify(): \think\Response
    {
        $channel = (string) input('post.channel', 'feishu');

        if ($channel === 'wecom') {
            $url = WecomNotifyService::webhook();
            if ($url === '') {
                return $this->error('还没填企业微信机器人地址');
            }
            if (!WecomNotifyService::isValidWebhook($url)) {
                return $this->error('地址不合法，必须是 https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key= 开头');
            }
            $ok = WecomNotifyService::send("### 🔔 通知测试\n> 收到这条消息说明企业微信通知已接通\n> 时间：" . date('Y-m-d H:i:s'));
            return $ok
                ? $this->success(null, '已发送，请到企业微信群确认')
                : $this->error('发送失败，请检查机器人地址是否正确');
        }

        $result = FeishuNotifyService::test();
        return $result['ok']
            ? $this->success(null, $result['msg'])
            : $this->error($result['msg']);
    }

    public function payment(): \think\Response
    {
        $alipay  = PaymentConfig::where('type', 1)->find();
        $wechat  = PaymentConfig::where('type', 2)->find();
        return $this->view('config/payment', compact('alipay', 'wechat') + ['currentMenu' => 'config_payment', 'pageTitle' => '支付设置']);
    }

    public function savePayment(): \think\Response
    {
        $type   = (int) input('post.type');
        if (!in_array($type, [1, 2], true)) return $this->error('支付类型无效');
        $config = input('post.config', '');
        $status = (int) input('post.status', 0);

        $record = PaymentConfig::where('type', $type)->find();
        if ($record) {
            $record->allowField(['config', 'status'])->save(['config' => $config, 'status' => $status]);
        } else {
            $names  = [1 => '支付宝', 2 => '微信支付', ];
            $record = new PaymentConfig();
            $record->save(['type' => $type, 'name' => $names[$type] ?? '', 'config' => $config, 'status' => $status]);
        }
        LogService::record('支付配置', '保存', '保存支付配置');
        return $this->success();
    }

    /**
     * POST /admin/config/upload-key  上传微信支付证书文件
     *
     * 自动识别传的是哪个文件，不用用户自己分辨：
     *   apiclient_key.pem  —— 商户私钥，存到 protected/certs/（web 根目录之外）
     *   apiclient_cert.pem —— 商户证书，只用来读出证书序列号，不落盘
     */
    public function uploadKey(): \think\Response
    {
        $file = request()->file('file');
        if (!$file) {
            return $this->error('请选择证书文件');
        }
        if (strtolower($file->getOriginalExtension()) !== 'pem') {
            return $this->error('只支持 .pem 文件（apiclient_key.pem 或 apiclient_cert.pem）');
        }
        if ($file->getSize() > 16 * 1024) {
            return $this->error('文件过大，证书文件通常只有 1~2KB，请确认没传错文件');
        }

        $content = file_get_contents($file->getRealPath());

        // 证书：读出序列号回填，省得手抄 40 位十六进制
        if (str_contains($content, 'BEGIN CERTIFICATE')) {
            $info = openssl_x509_parse($content);
            if (!$info || empty($info['serialNumberHex'])) {
                return $this->error('证书解析失败，请确认是 apiclient_cert.pem');
            }
            return $this->success([
                'type'      => 'cert',
                'serial_no' => strtoupper($info['serialNumberHex']),
                'mchid'     => $info['subject']['OU'] ?? '',
                'expire'    => !empty($info['validTo_time_t']) ? date('Y-m-d', $info['validTo_time_t']) : '',
            ]);
        }

        // 私钥：落盘保存
        if (!openssl_pkey_get_private($content)) {
            return $this->error('文件不是有效的私钥或证书，请确认从商户平台下载的原始文件');
        }

        // 文件名固定，不采用上传方的名字，避免路径穿越
        $dir = root_path() . 'protected/certs/';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return $this->error('无法创建证书目录，请检查 protected/ 的写权限');
        }
        $absPath = $dir . 'apiclient_key.pem';
        if (file_put_contents($absPath, $content) === false) {
            return $this->error('写入失败，请检查 protected/certs/ 的写权限');
        }
        @chmod($absPath, 0600);

        LogService::record('支付配置', '上传私钥', '上传微信支付商户私钥');

        return $this->success([
            'type'        => 'key',
            'path'        => $absPath,
            'fingerprint' => strtoupper(substr(hash('sha256', $content), 0, 16)),
        ]);
    }

    public function uploadImage(): \think\Response
    {
        $file = request()->file('file');
        if (!$file) {
            return $this->error('请选择图片');
        }
        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'])) {
            return $this->error('只支持 JPG/PNG/GIF/WebP/ICO 格式');
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            return $this->error('图片不能超过 2MB');
        }
        $dir = public_path() . 'uploads/config/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $saveName = md5(uniqid()) . '.' . $ext;
        $file->move($dir, $saveName);

        // ICO 不会被处理，favicon 保持原样
        $optimized = ImageOptimizeService::optimize($dir . $saveName, 600);
        if ($optimized !== null) {
            $saveName = basename($optimized);
        }

        return $this->success(['url' => '/uploads/config/' . $saveName]);
    }
}
