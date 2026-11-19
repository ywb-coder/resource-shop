<?php
namespace app\home\service;

use app\common\model\Question;
use app\common\service\NotifyService;

class QuestionService
{
    /** 同一用户未回复的提问上限，防刷 */
    public const PENDING_LIMIT = 5;

    public function create(int $userId, string $content, string $contact = ''): array
    {
        $content = trim($content);
        if (mb_strlen($content) < 5) {
            throw new \RuntimeException('请把问题描述清楚一些（至少 5 个字）');
        }
        if (mb_strlen($content) > 1000) {
            throw new \RuntimeException('问题内容不要超过 1000 字');
        }

        $pending = Question::where('user_id', $userId)->where('status', 0)->count();
        if ($pending >= self::PENDING_LIMIT) {
            throw new \RuntimeException('你还有 ' . $pending . ' 个问题在等待回复，请先等等');
        }

        $q = new Question();
        $q->save([
            'user_id' => $userId,
            'content' => $content,
            'contact' => mb_substr(trim($contact), 0, 100),
            'status'  => 0,
        ]);
        // 有人提问就推到飞书，运营不用天天刷后台
        NotifyService::questionCreated($q->toArray(), $userId);
        return $q->toArray();
    }

    public function listByUser(int $userId, int $page = 1, int $pageSize = 10): array
    {
        $query = Question::where('user_id', $userId)->order('id', 'desc');
        $total = (clone $query)->count();
        $list  = $query->page($page, $pageSize)->select()->toArray();
        return compact('list', 'total', 'page', 'pageSize');
    }

    /** 有几个问题已经回复了但用户还没看到，用于入口红点 */
    public function repliedCount(int $userId): int
    {
        return Question::where('user_id', $userId)->where('status', 1)->count();
    }
}
