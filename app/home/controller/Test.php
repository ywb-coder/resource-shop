<?php
namespace app\home\controller;

use app\common\service\SystemConfigService;

/**
 * 在线心理测试。
 *
 * 测试题本身是 22 个自包含的单文件 HTML（内联了全部 CSS/JS/题目与计分逻辑），
 * 放在 public/tests/ 下作为静态资源直接托管。本控制器只负责：
 *   - /test      统一入口（卡片列表 + 分类筛选 + 搜索）
 *   - /test/play 在线答题页（把对应 HTML 用 iframe 嵌入，带返回栏）
 *
 * 答题逻辑完全沿用原题文件，平台只提供壳和入口。
 */
class Test extends Base
{
    private function tests(): array
    {
        $path = public_path() . 'tests' . DIRECTORY_SEPARATOR . 'index.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function find(string $uri): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $uri)) {
            return null;
        }
        foreach ($this->tests() as $t) {
            if (($t['uri'] ?? '') === $uri) {
                return $t;
            }
        }
        return null;
    }

    public function index(): \think\Response
    {
        // /test?play=xxx 直接渲染答题页，规避多应用 express 模式下 /test/play 的路由歧义
        $play = (string) ($_GET['play'] ?? '');
        if ($play !== '') {
            return $this->renderPlay($play);
        }

        $tests = $this->tests();

        $currentCat = (string) ($_GET['cat'] ?? '');
        $keyword    = (string) ($_GET['q'] ?? '');

        $categories = [];
        $filtered   = [];
        foreach ($tests as $t) {
            $cat = (string) ($t['category'] ?? '其他');
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;
            // 模板引擎不擅长算术，浏览量在这里算好
            $t['views_total'] = (int) ($t['views'] ?? 0) + (int) ($t['views_base'] ?? 0);

            if ($currentCat !== '' && $cat !== $currentCat) {
                continue;
            }
            if ($keyword !== '' && mb_strpos((string) ($t['title'] ?? '') . (string) ($t['description'] ?? ''), $keyword) === false) {
                continue;
            }
            $filtered[] = $t;
        }
        ksort($categories);

        return $this->view('test/index', [
            'tests'        => $filtered,
            'testCount'    => count($tests),
            'testCats'     => $categories,
            'currentCat'   => $currentCat,
            'keyword'      => $keyword,
            'hideTabbar'   => false,
            'seo_title'    => '在线心理测试 - ' . SystemConfigService::get('site_name', '资料站'),
            'seo_keywords' => '心理测试,在线测试,性格测试,趣味测试,自我探索',
            'seo_description' => '精选在线心理测试，涵盖自我探索、情绪压力、心理健康与趣味测试，打开即测。',
        ]);
    }

    public function play(): \think\Response
    {
        return $this->renderPlay((string) ($_GET['uri'] ?? ''));
    }

    private function renderPlay(string $uri): \think\Response
    {
        $test = $this->find($uri);
        if (!$test) {
            return response('测试题不存在', 404);
        }
        $file = public_path() . 'tests' . DIRECTORY_SEPARATOR . ($test['file'] ?? '');
        if (!is_file($file)) {
            return response('测试题文件缺失', 404);
        }
        return $this->view('test/play', [
            'test'        => $test,
            'iframeUrl'   => '/tests/' . rawurlencode((string) ($test['file'] ?? '')),
            'hideTabbar'  => true,
            'seo_title'   => ($test['title'] ?? '在线测试') . ' - ' . SystemConfigService::get('site_name', '资料站'),
            'seo_keywords' => ($test['title'] ?? '') . ',心理测试,在线测试',
            'seo_description' => $test['description'] ?? '',
        ]);
    }
}
