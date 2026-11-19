<?php
namespace app\admin\service;

use app\common\model\CartItem;
use app\common\model\Material;
use app\common\model\MaterialPreview;
use app\common\model\Order;
use app\common\model\OrderItem;
use app\common\service\BaseCrudService;
use app\common\service\DeliveryService;
use app\common\service\ImageOptimizeService;
use app\common\service\WatermarkService;
use think\db\BaseQuery;

class MaterialService extends BaseCrudService
{
    /**
     * 试看图允许的最大像素数（宽 × 高）。
     *
     * 限文件大小挡不住 GD：它的开销跟像素数走，一张 2MB 的高压缩 JPG 也可能是
     * 8000×6000。这个上限只用来挡真正离谱的输入（解压炸弹那种几亿像素的图，
     * 会把请求拖到超时），正常的扫描件、手机照片都在范围内。
     */
    private const PREVIEW_MAX_PIXELS = 100000000;

    protected function modelClass(): string
    {
        return Material::class;
    }

    protected function allowFields(): array
    {
        return [
            'category_id', 'goods_type', 'res_type', 'title', 'cover', 'summary', 'description',
            'catalog', 'page_count', 'file_format', 'price', 'original_price', 'delivery_type',
            'pan_url', 'pan_code', 'file_path', 'file_name', 'file_size',
            'use_url', 'use_guide',
            'wechat', 'tags', 'seo_title', 'seo_keywords', 'seo_description',
            'status', 'is_hidden', 'is_hot', 'is_recommend', 'sort',
        ];
    }

    /**
     * delivery_type 不再由运营手动选，按实际填了网盘/文件自动推导：
     * 两种都配就是「都支持」，让用户自己挑顺手的用。
     */
    public function create(array $data): array
    {
        $data['delivery_type'] = DeliveryService::typeOf($data);
        return parent::create($data);
    }

    public function update(int $id, array $data): array
    {
        $data['delivery_type'] = DeliveryService::typeOf($data);
        return parent::update($id, $data);
    }

    /**
     * 发布表单的「根据已有内容提示」：常用标签、近期标题、各分类现有数量。
     * 只读最近 600 条，够用又不至于每次打开表单都扫全表。
     */
    public function formSuggest(): array
    {
        $rows = Material::field('category_id,title,tags,summary')
            ->order('id', 'desc')->limit(600)->select()->toArray();

        $tags = [];
        $catCounts = [];
        $titles = [];
        $summaries = [];
        foreach ($rows as $r) {
            $cid = (int) $r['category_id'];
            $catCounts[$cid] = ($catCounts[$cid] ?? 0) + 1;
            foreach (array_filter(array_map('trim', explode(',', (string) $r['tags']))) as $t) {
                $tags[$t] = ($tags[$t] ?? 0) + 1;
            }
            if (count($titles) < 15 && mb_strlen((string) $r['title']) <= 30) {
                $titles[] = $r['title'];
            }
            if (count($summaries) < 8 && mb_strlen((string) $r['summary']) > 20) {
                $summaries[] = $r['summary'];
            }
        }
        arsort($tags);

        return [
            'tags'      => array_slice($tags, 0, 48, true),
            'titles'    => $titles,
            'summaries' => $summaries,
            'catCounts' => $catCounts,
        ];
    }

    /** 列表：卡密商品附上库存 */
    public function list(array $params = [], int $page = 1, int $pageSize = 20): array
    {
        $result = parent::list($params, $page, $pageSize);

        // 下载量：一次查完当前页，避免逐行查库
        $downloads = \app\common\service\DownloadService::totals(
            array_map(fn ($r) => (int) $r['id'], $result['list'])
        );

        foreach ($result['list'] as &$row) {
            $row['download_count'] = $downloads[(int) $row['id']] ?? 0;
            if ((int) ($row['goods_type'] ?? 1) === \app\common\service\CardKeyService::GOODS_CARD) {
                $row['stock'] = \app\common\service\CardKeyService::stock((int) $row['id']);
            }
        }
        unset($row);
        return $result;
    }

    protected function buildListQuery(array $params): BaseQuery
    {
        $query = Material::with(['category'])->order('id', 'desc');
        if (!empty($params['keyword'])) {
            $query->whereLike('title', '%' . $params['keyword'] . '%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }
        if (isset($params['hidden']) && $params['hidden'] !== '') {
            $query->where('is_hidden', (int) $params['hidden']);
        }
        if (!empty($params['category_id'])) {
            $query->whereIn('category_id', \app\common\service\CategoryTree::ids((int) $params['category_id']));
        }
        return $query;
    }

    /** 下拉筛选用的精简列表，不带关联、不取大字段 */
    public function options(): array
    {
        return Material::field('id,title')->order('id', 'desc')->select()->toArray();
    }

    public function detail(int $id): array
    {
        $material = Material::with(['category', 'previews'])->findOrFail($id);
        $data = $material->toArray();
        $data['stock'] = \app\common\service\CardKeyService::stock($id);
        return $data;
    }

    public function uploadFile(\think\File $file, string $type = 'material'): array
    {
        $ext = strtolower($file->getOriginalExtension());

        if ($type === 'files') {
            // 资料文件：允许常见文档/压缩格式，限 200MB
            $allowExt  = ['pdf','doc','docx','xls','xlsx','ppt','pptx','zip','rar','7z','mp4','mp3'];
            $maxBytes  = 200 * 1024 * 1024;
            if (!in_array($ext, $allowExt)) {
                throw new \RuntimeException('不支持的文件格式：' . $ext);
            }
            $fileSize = $file->getSize();
            if ($fileSize > $maxBytes) {
                throw new \RuntimeException('文件不能超过 200MB');
            }
            $saveName = md5(uniqid()) . '.' . $ext;
            $relPath  = 'protected/materials/' . date('Ymd');
            $absDir   = root_path() . $relPath;
            if (!is_dir($absDir)) mkdir($absDir, 0755, true);
            $file->move($absDir, $saveName);
            return [
                'path'      => $relPath . '/' . $saveName,
                'name'      => $file->getOriginalName(),
                'size'      => $fileSize,
                'extension' => $ext,
            ];
        }

        // 封面：只允许图片，限 5MB
        $allowImg = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowImg)) {
            throw new \RuntimeException('封面只支持 JPG/PNG/GIF/WebP');
        }
        $fileSize = $file->getSize();
        if ($fileSize > 5 * 1024 * 1024) {
            throw new \RuntimeException('封面图片不能超过 5MB');
        }
        $saveName = md5(uniqid()) . '.' . $ext;
        $savePath = 'uploads/' . $type . '/' . date('Ymd');
        $file->move(public_path() . $savePath, $saveName);

        // 压缩并生成列表缩略图，压缩后扩展名可能变成 jpg
        $absPath = public_path() . $savePath . '/' . $saveName;
        $optimized = ImageOptimizeService::optimize($absPath);
        if ($optimized !== null) {
            $absPath  = $optimized;
            $saveName = basename($optimized);
            $ext      = strtolower(pathinfo($saveName, PATHINFO_EXTENSION));
        }
        ImageOptimizeService::makeThumb($absPath);

        return [
            'path'      => '/' . $savePath . '/' . $saveName,
            'name'      => $file->getOriginalName(),
            'size'      => filesize($absPath),
            'extension' => $ext,
        ];
    }

    public function uploadPreview(int $materialId, \think\File $file): array
    {
        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            throw new \RuntimeException('试看图只支持 JPG/PNG/GIF/WebP');
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new \RuntimeException('试看图不能超过 5MB');
        }

        $info = @getimagesize($file->getPathname());
        if (!$info) {
            throw new \RuntimeException('这张图读不出来，可能不是真正的图片文件，请换一张');
        }
        [$imgWidth, $imgHeight] = $info;
        if ($imgWidth * $imgHeight > self::PREVIEW_MAX_PIXELS) {
            throw new \RuntimeException(sprintf(
                '试看图分辨率过大（%d×%d）。试看图最终只展示到 %dpx，请先缩到 4000px 以内再上传',
                $imgWidth,
                $imgHeight,
                ImageOptimizeService::MAX_EDGE
            ));
        }

        $day = date('Ymd');

        // 原图存 public 之外，防止通过 URL 直接拿到无水印图
        $origRel = 'protected/previews/' . $day;
        $origDir = root_path() . $origRel . '/';
        if (!is_dir($origDir)) {
            mkdir($origDir, 0755, true);
        }
        $origName = md5(uniqid()) . '.' . $ext;
        $file->move($origDir, $origName);

        // 水印图存 public，文件名与原图无关联
        $wmDir = public_path() . 'uploads/preview/' . $day . '/';
        if (!is_dir($wmDir)) {
            mkdir($wmDir, 0755, true);
        }

        $work = $wmDir . 'tmp_' . md5(uniqid()) . '.' . $ext;
        try {
            // 先把副本压到展示尺寸，再打水印。反过来做的话水印要在原始分辨率上
            // 画几千次文字，大图慢到超时是常事——而最终对外的图本来就只有 1200px。
            if (!@copy($origDir . $origName, $work)) {
                throw new \RuntimeException('试看图暂存失败，请检查 uploads 目录是否可写');
            }
            $scaled = ImageOptimizeService::optimize($work);
            if ($scaled !== null) {
                $work = $scaled; // 压缩后扩展名可能从 png 变成 jpg
            }

            $wmName = md5(uniqid()) . '.' . strtolower(pathinfo($work, PATHINFO_EXTENSION));
            WatermarkService::addWatermark($work, $wmDir . $wmName);
            ImageOptimizeService::makeThumb($wmDir . $wmName);
        } catch (\Throwable $e) {
            // 失败就把已经落地的文件清掉，别在 protected 下攒垃圾
            @unlink($work);
            @unlink($origDir . $origName);
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            // GD 这类异常直接往上抛会变成 500 白页，后台只看得到「HTTP 500」
            \think\facade\Log::error('uploadPreview failed: ' . $e->getMessage());
            throw new \RuntimeException('试看图处理失败：' . $e->getMessage());
        }
        @unlink($work);

        $maxSort   = MaterialPreview::where('material_id', $materialId)->max('sort') ?? 0;
        $preview   = new MaterialPreview();
        $preview->save([
            'material_id'   => $materialId,
            'image_path'    => '/uploads/preview/' . $day . '/' . $wmName,
            'original_path' => $origRel . '/' . $origName,
            'sort'          => $maxSort + 1,
        ]);
        return $preview->toArray();
    }

    public function deletePreview(int $previewId): bool
    {
        $preview = MaterialPreview::findOrFail($previewId);
        // 删除文件（原图新数据在 protected/ 下，旧数据在 public/uploads/ 下）
        $wmAbs = public_path() . ltrim($preview->image_path, '/');
        @unlink($wmAbs);
        @unlink(ImageOptimizeService::thumbPath($wmAbs)); // 列表缩略图跟着一起删，否则会一直攒在磁盘上
        if ($preview->original_path) {
            $orig = str_starts_with($preview->original_path, 'protected/')
                ? root_path() . $preview->original_path
                : public_path() . ltrim($preview->original_path, '/');
            @unlink($orig);
        }
        return $preview->delete();
    }

    public function updateStatus(int $id, int $status): void
    {
        Material::where('id', $id)->update(['status' => $status]);
    }

    /**
     * 删除资料，连同它自己的文件一起清掉。
     *
     * 已被购买的资料不给删：买家的「我的下载」会直接失效，这是拿用户已付
     * 的钱换一次误操作。需要下架就用状态开关，或者用隐藏。
     */
    public function delete(int $id): bool
    {
        $material = Material::findOrFail($id);

        $ordered = OrderItem::where('material_id', $id)->count()
            + Order::where('material_id', $id)->count();
        if ($ordered > 0) {
            throw new \RuntimeException(
                "该资料已产生 {$ordered} 条购买记录，删除会让买家无法下载。请改用「下架」或「隐藏」。"
            );
        }

        // 预览图有自己的水印图和原图两份文件，交给现成的方法逐条清
        foreach (MaterialPreview::where('material_id', $id)->select() as $preview) {
            $this->deletePreview((int) $preview->id);
        }

        $this->unlinkAsset((string) $material->cover);
        $this->unlinkAsset((string) $material->file_path);

        // 购物车里躺着的要一并清掉，否则结算时会查不到资料而报错
        CartItem::where('material_id', $id)->delete();

        return $material->delete();
    }

    /**
     * 删掉一个资料文件。路径可能是 protected/ 下的（web 根之外），
     * 也可能是 /uploads/ 下的，两种都要能落到真实位置。
     */
    private function unlinkAsset(string $path): void
    {
        $path = trim($path);
        if ($path === '') {
            return;
        }
        $abs = str_starts_with($path, 'protected/')
            ? root_path() . $path
            : public_path() . ltrim($path, '/');
        // 文件可能早被手工删过，不存在不算失败
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}
