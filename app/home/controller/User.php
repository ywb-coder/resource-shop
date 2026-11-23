<?php
namespace app\home\controller;

use app\common\model\Material;
use app\common\model\Order;
use app\common\model\User as UserModel;
use app\common\service\ImageOptimizeService;
use app\home\service\UserService as US;

class User extends Base
{
    public function login(): \think\Response
    {
        $account  = input('post.account', '');
        $password = input('post.password', '');
        try {
            $user = (new US())->login($account, $password);
            \think\facade\Session::regenerate(); // 防会话固定
            session('user_id', $user['id']);
            session('user_info', $this->sessionUser($user));
            return $this->success(['nickname' => $user['nickname']]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function register(): \think\Response
    {
        $account  = input('post.account', '');
        $password = input('post.password', '');
        $nickname = input('post.nickname', '');
        try {
            $user = (new US())->register($account, $password, $nickname);
            \think\facade\Session::regenerate(); // 防会话固定
            session('user_id', $user['id']);
            session('user_info', $this->sessionUser($user));
            return $this->success(['nickname' => $user['nickname']]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function logout(): mixed
    {
        \app\common\service\VideoService::clearDeviceCookies();
        \think\facade\Session::destroy();
        return redirect('/');
    }

    public function profile(): \think\Response
    {
        $this->requireLogin();
        return $this->view('user/profile', ['seo_title' => '个人资料', 'profile' => UserModel::find($this->userId)?->toArray() ?? []]);
    }

    public function profileSave(): \think\Response
    {
        $this->requireLogin();
        $nickname = trim(input('post.nickname', ''));
        $password = trim(input('post.password', ''));
        $oldPwd   = trim(input('post.old_password', ''));

        $user = UserModel::find($this->userId);
        if (!$user) return $this->error('用户不存在');

        $save = [];
        if ($nickname) {
            if (mb_strlen($nickname) > 20) return $this->error('昵称不超过20个字');
            $save['nickname'] = $nickname;
        }
        if ($password) {
            if (!$oldPwd) return $this->error('请输入当前密码');
            if (!password_verify($oldPwd, $user->password)) return $this->error('当前密码不正确');
            if (strlen($password) < 6) return $this->error('新密码不能少于6位');
            $save['password'] = password_hash($password, PASSWORD_BCRYPT);
        }
        if (empty($save)) return $this->error('没有任何修改');

        $user->save($save);
        // 刷新 session
        $info = session('user_info') ?? [];
        if (isset($save['nickname'])) $info['nickname'] = $save['nickname'];
        session('user_info', $info);

        return $this->success(['nickname' => $info['nickname'] ?? '']);
    }

    public function avatarUpload(): \think\Response
    {
        $this->requireLogin();
        $file = request()->file('avatar');
        if (!$file) return $this->error('请选择图片');

        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            return $this->error('只支持 JPG/PNG/GIF/WebP 格式');
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            return $this->error('图片不能超过 2MB');
        }

        $dir  = public_path() . 'uploads/avatar/' . date('Ym') . '/';
        $name = md5($this->userId . uniqid()) . '.' . $ext;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file->move($dir, $name);

        $optimized = ImageOptimizeService::optimize($dir . $name, 300);
        if ($optimized !== null) {
            $name = basename($optimized);
        }

        $url = '/uploads/avatar/' . date('Ym') . '/' . $name;
        UserModel::where('id', $this->userId)->update(['avatar' => $url]);

        // 刷新 session
        $info = session('user_info') ?? [];
        $info['avatar'] = $url;
        session('user_info', $info);

        return $this->success(['url' => $url]);
    }

    public function center(): \think\Response
    {
        $this->requireLogin();
        $page    = max(1, (int) input('get.page', 1));
        $result  = (new US())->getOrders($this->userId, $page, 10);

        return $this->view('user/center', [
            'result'    => $result,
            'seo_title' => '我的资料 - ' . ($this->loginUser['nickname'] ?? ''),
        ]);
    }

    /** 我的课程：卡密兑换的课程（权益在授权表，不在订单表） */
    public function courses(): \think\Response
    {
        $this->requireLogin();
        return $this->view('user/courses', [
            'courses'   => \app\common\service\VideoService::coursesOfUser($this->userId),
            'seo_title' => '我的课程',
        ]);
    }

    public function orders(): \think\Response
    {
        $this->requireLogin();
        \app\home\service\OrderService::closeExpired($this->userId); // 惰性关闭超时订单，不依赖定时任务
        $page  = max(1, (int) input('get.page', 1));
        $query = Order::where('user_id', $this->userId)->order('id', 'desc');
        $total = (clone $query)->count();
        $list  = $query->page($page, 10)->select()->toArray();

        $materialIds = array_filter(array_column($list, 'material_id'));
        $materials   = [];
        if ($materialIds) {
            $rows = Material::whereIn('id', $materialIds)->field('id,cover')->select()->toArray();
            foreach ($rows as $m) $materials[$m['id']] = $m;
        }
        foreach ($list as &$order) {
            $order['cover']        = $order['cover'] ?: ($materials[$order['material_id']]['cover'] ?? '');
            $isClosed = (int)$order['status'] === 2;
            $isPaid   = (int)$order['pay_status'] === 1;
            $ts = $isPaid ? $order['pay_time'] : $order['create_time'];
            $order['time_label'] = ($isPaid ? '支付时间：' : '下单时间：')
                . date('Y-m-d H:i', is_numeric($ts) ? (int)$ts : strtotime((string)$ts));
            [$order['status_label'], $order['status_text']] = match(true) {
                $isClosed             => ['closed',  '已关闭'],
                $isPaid               => ['paid',    '已支付'],
                (int)$order['pay_status'] === 2 => ['refund',  '已退款'],
                default               => ['pending', '待支付'],
            };
        }

        return $this->view('user/orders', [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'seo_title' => '我的订单',
        ]);
    }

    public function download(): \think\Response
    {
        $this->requireLogin();
        $orderId    = (int) input('get.order_id');
        $materialId = (int) input('get.material_id');

        // 合并订单一单多份资料，光有 order_id 无法确定下载哪一份，
        // 所以优先按 material_id 走明细鉴权；只给 order_id 时取该单唯一的那份。
        if (!$materialId && $orderId) {
            $items = \app\common\model\OrderItem::alias('i')
                ->join('order o', 'o.id = i.order_id')
                ->where('o.user_id', $this->userId)
                ->where('o.pay_status', 1)
                ->where('i.order_id', $orderId)
                ->column('i.material_id');
            if (count($items) === 1) {
                $materialId = (int) $items[0];
            } elseif (count($items) > 1) {
                return $this->error('该订单包含多份资料，请指定要下载的资料');
            }
        }
        if (!$materialId) {
            return $this->error('缺少资料信息');
        }

        $item = \app\home\service\OrderService::paidItem($this->userId, $materialId);
        if (!$item) {
            return $this->error('无权限，请先购买该资料');
        }

        $material = Material::find($materialId);
        if (!$material) {
            return $this->error('资料不存在');
        }

        // 记录下载（同一用户 60 秒内重复拉同一份只记一次，详见 DownloadService）
        \app\common\service\DownloadService::record(
            (int) $material->id,
            $this->userId,
            (float) $material->price > 0
                ? \app\common\service\DownloadService::TYPE_PAID
                : \app\common\service\DownloadService::TYPE_FREE,
            (int) $item->order_id
        );

        return $this->deliverMaterial($material, $this->userId);
    }





    /** session 里只保留页面展示要用的字段，密码哈希等不入会话 */
    private function sessionUser(array $user): array
    {
        return [
            'id'       => (int) ($user['id'] ?? 0),
            'nickname' => $user['nickname'] ?? '',
            'avatar'   => $user['avatar'] ?? '',
            'phone'    => $user['phone'] ?? '',
            'email'    => $user['email'] ?? '',
        ];
    }
}
