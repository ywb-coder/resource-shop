<?php
namespace app\admin\controller;

use app\admin\service\CategoryService;
use app\admin\service\MaterialService;
use app\common\service\LogService;

class Material extends Base
{
    private MaterialService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->checkLogin();
        $this->service = new MaterialService();
    }

    public function index(): \think\Response
    {
        $categories = (new CategoryService())->all();
        return $this->view('material/index', compact('categories') + ['currentMenu' => 'material', 'pageTitle' => '资料管理']);
    }

    public function list(): \think\Response
    {
        $params   = input('get.');
        $page     = (int) input('get.page', 1);
        $pageSize = (int) input('get.page_size', 15);
        $result   = $this->service->list($params, $page, $pageSize);
        return $this->success($result);
    }

    public function create(): \think\Response
    {
        $categories = (new CategoryService())->all();
        $suggest    = $this->service->formSuggest();
        return $this->view('material/form', compact('categories', 'suggest') + ['currentMenu' => 'material', 'pageTitle' => '新增资料']);
    }

    public function edit(): \think\Response
    {
        $id         = (int) input('get.id');
        $material   = $this->service->detail($id);
        $categories = (new CategoryService())->all();
        $suggest    = $this->service->formSuggest();
        return $this->view('material/form', compact('material', 'categories', 'suggest') + ['currentMenu' => 'material', 'pageTitle' => '编辑资料']);
    }

    public function save(): \think\Response
    {
        $data  = input('post.');
        $id    = (int) ($data['id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return $this->error('请填写资料标题');
        }
        // catalog 是 JSON 字符串
        if (isset($data['catalog']) && is_array($data['catalog'])) {
            $data['catalog'] = json_encode($data['catalog'], JSON_UNESCAPED_UNICODE);
        }
        if ($id) {
            $this->service->update($id, $data);
            LogService::record('资料管理', '编辑', '编辑资料：' . $title);
        } else {
            $record = $this->service->create($data);
            $id     = $record['id'];
            LogService::record('资料管理', '新增', '新增资料：' . $title);
        }
        return $this->success(['id' => $id]);
    }

    /** 上架 / 下架切换 */
    public function status(): \think\Response
    {
        $id     = (int) input('post.id');
        $status = (int) input('post.status');
        if (!in_array($status, [0, 1], true)) {
            return $this->error('状态参数不正确');
        }
        $material = \app\common\model\Material::find($id);
        if (!$material) {
            return $this->error('资料不存在');
        }
        $this->service->updateStatus($id, $status);
        LogService::record('资料管理', $status === 1 ? '上架' : '下架', ($status === 1 ? '上架' : '下架') . '资料：' . $material->title);
        return $this->success(['status' => $status]);
    }

    public function uploadCover(): \think\Response
    {
        $file = request()->file('file');
        if (!$file) return $this->error('请选择文件');
        try {
            $result = $this->service->uploadFile($file, 'cover');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return $this->success(['url' => $result['path']]);
    }

    public function uploadFile(): \think\Response
    {
        $file = request()->file('file');
        if (!$file) return $this->error('请选择文件');
        try {
            $result = $this->service->uploadFile($file, 'files');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return $this->success([
            'path' => $result['path'],
            'name' => $result['name'],
            'size' => $result['size'],
        ]);
    }

    public function uploadPreview(): \think\Response
    {
        $materialId = (int) input('post.material_id');
        $file       = request()->file('file');
        if (!$file) return $this->error('请选择文件');
        try {
            $result = $this->service->uploadPreview($materialId, $file);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        return $this->success($result);
    }

    public function deletePreview(): \think\Response
    {
        $id = (int) input('post.id');
        $this->service->deletePreview($id);
        return $this->success();
    }

    public function delete(): \think\Response
    {
        $id    = (int) input('post.id');
        $title = (string) \app\common\model\Material::where('id', $id)->value('title');

        try {
            $this->service->delete($id);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        LogService::record('资料管理', '删除', '删除了资料：' . ($title ?: ('ID ' . $id)));
        return $this->success();
    }
}
