<?php
namespace app\home\service;

use app\common\model\Material;
use app\common\model\Order;
use app\common\model\OrderItem;
use app\common\model\User;
use app\common\service\NotifyService;

class UserService
{
    public function register(string $account, string $password, string $nickname = ''): array
    {
        $isPhone = preg_match('/^1[3-9]\d{9}$/', $account);
        $isEmail = filter_var($account, FILTER_VALIDATE_EMAIL);

        if (!$isPhone && !$isEmail) {
            throw new \RuntimeException('请输入有效的手机号或邮箱');
        }
        if (strlen($password) < 6) {
            throw new \RuntimeException('密码不能少于6位');
        }
        if (mb_strlen($nickname) > 20) {
            throw new \RuntimeException('昵称不超过20个字');
        }

        $field = $isPhone ? 'phone' : 'email';
        if (User::where($field, $account)->find()) {
            throw new \RuntimeException('该账号已注册');
        }

        $user = new User();
        try {
            $user->save([
                $field        => $account,
                'password'    => password_hash($password, PASSWORD_BCRYPT),
                'nickname'    => $nickname ?: ('用户' . substr($account, -4)),
                'register_ip' => request()->ip(),
            ]);
        } catch (\think\db\exception\PDOException $e) {
            // 上面的查重和这里的插入之间存在时间差，靠 uk_phone / uk_email 兜住并发
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new \RuntimeException('该账号已注册');
            }
            throw $e;
        }
        $data = $user->toArray();
        // 注册成功给运营群报一声，失败不影响注册
        NotifyService::userRegistered($data);
        return $data;
    }









    public function login(string $account, string $password): array
    {
        if ($account === '' || $password === '') {
            throw new \RuntimeException('请输入账号和密码');
        }

        // 暴力破解保护：同一 IP 5次失败锁定15分钟
        $lockKey  = 'login_fail:' . request()->ip();
        $attempts = (int) \think\facade\Cache::get($lockKey, 0);
        if ($attempts >= 5) {
            throw new \RuntimeException('登录失败次数过多，请 15 分钟后再试');
        }

        $user = User::where('phone', $account)->whereOr('email', $account)->find();
        if (!$user || !password_verify($password, $user->password)) {
            \think\facade\Cache::set($lockKey, $attempts + 1, 900);
            throw new \RuntimeException('账号或密码错误');
        }
        if ($user->status != 1) {
            throw new \RuntimeException('账号已被禁用');
        }

        \think\facade\Cache::delete($lockKey);
        return $user->toArray();
    }

    /**
     * 「我的资料」：按资料列而不是按订单列。
     * 合并订单一单多份，用户关心的是买了哪些资料，不是下了几个单。
     */
    public function getOrders(int $userId, int $page = 1, int $pageSize = 20): array
    {
        OrderService::closeExpired($userId); // 惰性关闭超时订单，不依赖定时任务

        // 按已购资料明细展示，合并订单也可以逐份获取
        $query = OrderItem::alias('i')
            ->join('order o', 'o.id = i.order_id')
            ->where('o.user_id', $userId)
            ->where('o.pay_status', 1)
            ->field('i.material_id, i.title, i.cover, i.price, i.delivery_type,
                     o.id AS order_id, o.order_no, o.pay_time, o.amount AS order_amount')
            ->order('o.id', 'desc')
            ->order('i.id', 'asc');

        $total = (clone $query)->count();
        $list  = $query->page($page, $pageSize)->select()->toArray();

        // 明细里的封面是下单时的快照，可能已经被换过，用资料表的当前值兜底
        $ids = array_column($list, 'material_id');
        $now = $ids ? Material::whereIn('id', $ids)->column('cover,title', 'id') : [];
        foreach ($list as &$row) {
            $cur = $now[$row['material_id']] ?? null;
            $row['cover'] = $cur['cover'] ?? $row['cover'];
            $row['title'] = $cur['title'] ?? $row['title'];
            $row['amount'] = $row['price']; // 展示单份价格，不是整单金额
        }
        unset($row);

        return compact('list', 'total', 'page', 'pageSize');
    }

    public function hasPurchased(int $userId, int $materialId): bool
    {
        return OrderService::hasPurchased($userId, $materialId);
    }
}
