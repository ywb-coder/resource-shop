<?php
use think\facade\Route;

// 登录
Route::get('/login', 'Login/index');
Route::post('/login', 'Login/doLogin');
Route::get('/logout', 'Login/logout');
Route::post('/password', 'Login/changePassword');

// 首页仪表板
Route::get('/', 'Index/index');
Route::get('/index', 'Index/index');

// 分类管理
Route::group('/category', function () {
    Route::get('/', 'Category/index');
    Route::get('/list', 'Category/list');
    Route::post('/save', 'Category/save');
    Route::post('/delete', 'Category/delete');
});

// 资料管理
Route::group('/material', function () {
    Route::get('/', 'Material/index');
    Route::get('/list', 'Material/list');
    Route::get('/create', 'Material/create');
    Route::get('/edit', 'Material/edit');
    Route::post('/save', 'Material/save');
    Route::post('/status', 'Material/status');
    Route::post('/upload/cover', 'Material/uploadCover');
    Route::post('/upload/file', 'Material/uploadFile');
    Route::post('/upload/preview', 'Material/uploadPreview');
    Route::post('/delete/preview', 'Material/deletePreview');
    Route::post('/delete', 'Material/delete');
});

// 首页精选（轮播 5 个位）
Route::group('/featured', function () {
    Route::get('/', 'Featured/index');
    Route::get('/data', 'Featured/data');
    Route::get('/search', 'Featured/search');
    Route::post('/set', 'Featured/set');
    Route::post('/clear', 'Featured/clear');
    Route::post('/pin', 'Featured/pin');
    Route::post('/auto', 'Featured/auto');
    Route::post('/refresh', 'Featured/refresh');
});

// 免费资料（上传复用资料管理的 /material/upload/* 接口）
Route::group('/freebie', function () {
    Route::get('/', 'Freebie/index');
    Route::get('/list', 'Freebie/list');
    Route::post('/save', 'Freebie/save');
    Route::post('/status', 'Freebie/status');
    Route::post('/delete', 'Freebie/delete');
});

// 卡密仓库
Route::group('/card', function () {
    Route::get('/', 'Card/index');
    Route::get('/products', 'Card/products');
    Route::get('/list', 'Card/list');
    Route::post('/import', 'Card/import');
    Route::post('/delete', 'Card/delete');
    Route::post('/unbind', 'Card/unbind');
    Route::post('/rebind', 'Card/rebind');
    Route::get('/export', 'Card/export');
});

// 订单管理
Route::group('/order', function () {
    Route::get('/', 'Order/index');
    Route::get('/list', 'Order/list');
});

// 用户管理
Route::group('/user', function () {
    Route::get('/', 'User/index');
    Route::get('/list', 'User/list');
    Route::post('/save', 'User/save');
    Route::post('/upload-avatar', 'User/uploadAvatar');
    Route::post('/reset-password', 'User/resetPassword');
    Route::post('/toggle-status', 'User/toggleStatus');
});

// 配置管理
Route::group('/config', function () {
    Route::get('/site', 'Config/site');
    Route::post('/site', 'Config/saveSite');
    Route::get('/payment', 'Config/payment');
    Route::post('/payment', 'Config/savePayment');
    Route::post('/upload-image', 'Config/uploadImage');
    // 通知渠道连通性测试（飞书 / 企业微信）
    Route::post('/notify-test', 'Config/testNotify');

    // 用户提问
    Route::get('/question', 'Question/index');
    Route::get('/question/list', 'Question/list');
    Route::post('/question/reply', 'Question/reply');
    Route::post('/question/delete', 'Question/delete');
    Route::post('/upload-key', 'Config/uploadKey');
});
// 课程课时管理页
Route::get('/course', 'Course/index');

// 视频课：分片上传（资料表单里的「视频文件」区块调用）
Route::group('/video', function () {
    Route::post('/init', 'Video/init');
    Route::post('/chunk', 'Video/chunk');
    Route::post('/finish', 'Video/finish');
    Route::post('/remove', 'Video/remove');
    // 课程课时
    Route::get('/course', 'Video/course');
    Route::post('/lesson/save', 'Video/lessonSave');
    Route::post('/lesson/delete', 'Video/lessonDelete');
    Route::post('/import', 'Video/import');
});


// 上线备忘
