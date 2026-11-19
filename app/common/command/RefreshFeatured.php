<?php
namespace app\common\command;

use app\common\service\FeaturedService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 每周一自动更新首页「本周精选」轮播。
 *
 * crontab：0 3 * * 1  php think featured:refresh
 */
class RefreshFeatured extends Command
{
    protected function configure(): void
    {
        $this->setName('featured:refresh')
             ->setDescription('按近 7 天下载量刷新首页本周精选（固定位不动）');
    }

    protected function execute(Input $input, Output $output): void
    {
        $stamp = date('Y-m-d H:i:s');

        if (!FeaturedService::autoEnabled()) {
            $output->writeln($stamp . ' 跳过：后台已关闭「每周自动更新」');
            return;
        }

        $service = new FeaturedService();
        $result  = $service->refresh();

        $titles = [];
        foreach ($service->forHome() as $item) {
            $titles[] = $item['position'] . '.' . $item['title'];
        }

        $output->writeln(sprintf(
            '%s 精选已更新：保留固定 %d 个，按下载量入选 %d 个，空位 %d 个',
            $stamp,
            $result['kept'],
            $result['picked'],
            $result['empty']
        ));
        if ($titles) {
            $output->writeln('  ' . implode(' / ', $titles));
        }
    }
}
