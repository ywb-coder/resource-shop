<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        // 定时任务：需要配置 crontab 每分钟执行
        'order:close'    => \app\common\command\CloseExpiredOrders::class,

        // 运维工具：批量压缩 public/uploads 下的图片并生成缩略图
        'image:optimize' => \app\common\command\OptimizeImages::class,

        // 运维工具：填完支付配置后自检，会联网校验微信签名是否正确
        'pay:check'      => \app\common\command\CheckPayConfig::class,

        // 运维工具：异步通知丢失时，拿订单号去支付宝查真实状态并补单
        'pay:sync'       => \app\common\command\SyncPayOrder::class,

        // 定时任务：每周一按下载量刷新首页「本周精选」轮播
        'featured:refresh' => \app\common\command\RefreshFeatured::class,
    ],
];
