<?php
use think\facade\Route;

Route::get('/', 'Index/index');

// 在线心理测试
Route::get('/test', 'Test/index');
Route::get('/test/play', 'Test/play');

// 资料
Route::get('/list', 'Material/list');
Route::get('/detail/<id>', 'Material/detail')->pattern(['id' => '\d+']);
Route::post('/buy', 'Material/buy');

// 购物车
Route::get('/cart/items', 'Cart/items');
Route::post('/cart/add', 'Cart/add');
Route::post('/cart/remove', 'Cart/remove');

// 支付
Route::post('/pay/submit', 'Pay/submit');
Route::get('/pay/alipay', 'Pay/alipay');
Route::get('/pay/wechat/oauth', 'Pay/wechatOauth');
Route::get('/pay/wechat', 'Pay/wechat')->completeMatch(); // 完整匹配，否则会吞掉 /pay/wechat/oauth
Route::get('/pay/status', 'Pay/status');
Route::get('/pay/channels', 'Pay/channels');
Route::get('/pay/return', 'Pay/return');
Route::post('/pay/notify/wechat', 'Pay/wechatNotify');
Route::post('/pay/notify/alipay', 'Pay/notify');
Route::post('/pay/notify', 'Pay/notify')->completeMatch(); // 完整匹配，且兼容早期配置的地址

// 免费资料专区
Route::get('/free', 'Free/index')->completeMatch();
Route::post('/free/claim', 'Free/claim');
Route::get('/free/file', 'Free/file');

// 邮件里的大文件下载链接（签名校验，不要求登录）
Route::get('/mail/file', 'Mail/file');

// 用户
Route::post('/user/login', 'User/login');
Route::post('/user/register', 'User/register');
Route::get('/user/logout', 'User/logout');
Route::get('/user/center', 'User/center');
Route::get('/user/orders', 'User/orders');

// 我有问题
Route::get('/user/questions', 'Question/index');
Route::post('/user/questions/submit', 'Question/submit');
Route::get('/user/download', 'User/download');
Route::get('/privacy', 'Page/privacy');
Route::get('/terms', 'Page/terms');
Route::get('/user/profile', 'User/profile');
Route::post('/user/profile/save', 'User/profileSave');
Route::post('/user/avatar/upload', 'User/avatarUpload');
