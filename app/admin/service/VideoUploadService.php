<?php
namespace app\admin\service;

/**
 * 视频上传：分片 → 合并 → ffmpeg 规整 (faststart) → 落到 protected/videos。
 *
 * 为什么要分片：PHP 默认 upload_max_filesize 只有 2M，nginx 也只有 32M，
 * 单个请求传几百 MB 的视频基本必失败，而且断了要从头再来。
 * 这里按 8MB 一片传，每片都是普通小请求，断了只重传那一片。
 *
 * 为什么不转码：这台机器 2 核 1.6G 且没有 swap，转 1080p 会直接 OOM。
 * 只做 `-c copy -movflags +faststart` 重封装——不重新编码、几乎不耗 CPU，
 * 但能把 moov 挪到文件头，浏览器才能「点了就播」而不是等整个文件下完。
 */
class VideoUploadService
{
    /** 临时目录（相对项目根），合并完成后清空 */
    private const TMP = 'runtime/video_upload/';

    /** 正式目录（相对项目根），web 根之外，外网无直链 */
    private const DIR = 'protected/videos';

    /** 单片上限，跟前端约定一致 */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /** 整片上限 3GB */
    private const MAX_BYTES = 3221225472;

    /** 允许的容器：先统一当 mp4 处理 */
    private const ALLOW_EXT = ['mp4', 'mov', 'm4v'];

    /** 浏览器能直接解的编码 */
    private const ALLOW_CODEC = ['h264', 'hevc', 'av1', 'vp9'];

    public function init(string $name, int $size, int $chunks): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOW_EXT, true)) {
            throw new \RuntimeException('只支持 mp4 / mov 格式，其他格式请先自行转成 mp4');
        }
        if ($size <= 0) {
            throw new \RuntimeException('文件大小异常，请重新选择');
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('单个视频不能超过 3GB');
        }
        if ($chunks <= 0 || $chunks > 20000) {
            throw new \RuntimeException('分片数量异常');
        }
        if (ceil($size / self::CHUNK_BYTES) < $chunks - 1) {
            throw new \RuntimeException('分片数量与文件大小不匹配');
        }

        $uploadId = date('YmdHis') . bin2hex(random_bytes(5));
        $dir      = root_path() . self::TMP . $uploadId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('创建临时目录失败，请检查 runtime 目录权限');
        }
        file_put_contents($dir . '/meta.json', json_encode([
            'name'   => mb_substr($name, 0, 200),
            'size'   => $size,
            'chunks' => $chunks,
            'ext'    => $ext,
            'time'   => time(),
        ], JSON_UNESCAPED_UNICODE));

        return [
            'upload_id'  => $uploadId,
            'chunk_size' => self::CHUNK_BYTES,
            'chunks'     => $chunks,
        ];
    }

    /** 收一片 */
    public function saveChunk(string $uploadId, int $index, \think\File $file): array
    {
        $dir  = $this->dir($uploadId);
        $meta = $this->meta($uploadId);
        if ($index < 0 || $index >= (int) $meta['chunks']) {
            throw new \RuntimeException('分片序号越界');
        }
        if ($file->getSize() > self::CHUNK_BYTES + 1024 * 1024) {
            throw new \RuntimeException('单片过大，请刷新页面重试');
        }

        $file->move($dir, sprintf('%06d.part', $index));

        $got  = count(glob($dir . '/*.part') ?: []);
        return ['received' => $got, 'total' => (int) $meta['chunks']];
    }

    /**
     * 合并 + 规整。返回可直接存进 shop_material 的字段。
     */
    public function finish(string $uploadId): array
    {
        $dir  = $this->dir($uploadId);
        $meta = $this->meta($uploadId);

        $chunks = (int) $meta['chunks'];
        for ($i = 0; $i < $chunks; $i++) {
            if (!is_file($dir . '/' . sprintf('%06d.part', $i))) {
                throw new \RuntimeException('还差第 ' . ($i + 1) . ' 片没传完，请重试');
            }
        }

        $raw = $dir . '/raw.' . $meta['ext'];
        $out = fopen($raw, 'wb');
        if (!$out) {
            throw new \RuntimeException('合并失败：无法写入临时文件');
        }
        for ($i = 0; $i < $chunks; $i++) {
            $part = $dir . '/' . sprintf('%06d.part', $i);
            $in   = fopen($part, 'rb');
            if (!$in) {
                fclose($out);
                throw new \RuntimeException('合并失败：分片读取异常');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            @unlink($part); // 边合并边删，省一半临时空间
        }
        fclose($out);

        $realSize = (int) filesize($raw);
        if ($realSize !== (int) $meta['size']) {
            @unlink($raw);
            $this->cleanup($dir);
            throw new \RuntimeException(sprintf(
                '文件大小对不上（应为 %s，实际 %s），请重传',
                $this->human((int) $meta['size']),
                $this->human($realSize)
            ));
        }

        // 统一转成 mp4 容器（mov/m4v 也走这里，codec 不变、不重新编码）
        $mp4 = $dir . '/final.mp4';
        if (strtolower($meta['ext']) === 'mp4' && $this->hasFastStart($raw) && $this->isMp4Container($raw)) {
            rename($raw, $mp4); // 已经是 faststart 的 mp4，白白重封装一遍没必要
        } else {
            $this->remux($raw, $mp4);
            @unlink($raw);
        }

        $probe = $this->probe($mp4);
        if ($probe['codec'] !== '' && !in_array($probe['codec'], self::ALLOW_CODEC, true)) {
            @unlink($mp4);
            $this->cleanup($dir);
            throw new \RuntimeException('这个视频的编码是 ' . $probe['codec'] . '，浏览器放不了。请先转成 H.264 的 mp4 再传');
        }
        if ($probe['duration'] <= 0) {
            @unlink($mp4);
            $this->cleanup($dir);
            throw new \RuntimeException('读不出视频时长，文件可能已损坏');
        }

        $relDir = self::DIR . '/' . date('Ym');
        $absDir = root_path() . $relDir;
        if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
            throw new \RuntimeException('创建视频目录失败，请检查 protected 目录权限');
        }
        $fileName = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.mp4';
        $absPath  = $absDir . '/' . $fileName;
        if (!rename($mp4, $absPath)) {
            throw new \RuntimeException('视频入库失败，请重试');
        }
        @chmod($absPath, 0644);
        $this->cleanup($dir);

        return [
            'path'     => $relDir . '/' . $fileName,
            'duration' => $probe['duration'],
            'size'     => (int) filesize($absPath),
            'width'    => $probe['width'],
            'height'   => $probe['height'],
            'codec'    => $probe['codec'],
            'warning'  => $probe['codec'] === 'hevc'
                ? '这是 H.265(HEVC) 编码，iPhone/Safari 能播，部分安卓浏览器和微信内置浏览器可能黑屏。建议转成 H.264 再上架。'
                : '',
        ];
    }

    /** 删除一个已入库的视频文件 */
    public function remove(string $relPath): bool
    {
        $relPath = trim($relPath);
        if ($relPath === '' || str_contains($relPath, '..') || !str_starts_with($relPath, self::DIR . '/')) {
            return false;
        }
        $abs  = root_path() . $relPath;
        $base = realpath(root_path() . self::DIR);
        $real = realpath($abs);
        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            return false;
        }
        return @unlink($real);
    }

    /**
     * 清掉超过 24 小时没完成的残片，避免用户中途关页面把磁盘占满。
     */
    public function gc(): void
    {
        $base = root_path() . self::TMP;
        foreach (glob($base . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $t = @filemtime($dir);
            if ($t && $t < time() - 86400) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
    }

    // ---------- 内部 ----------

    private function dir(string $uploadId): string
    {
        if (!preg_match('/^[0-9a-z]{18,40}$/', $uploadId)) {
            throw new \RuntimeException('上传会话无效');
        }
        $dir = root_path() . self::TMP . $uploadId;
        if (!is_dir($dir)) {
            throw new \RuntimeException('上传会话已过期，请重新上传');
        }
        return $dir;
    }

    private function meta(string $uploadId): array
    {
        $dir = $this->dir($uploadId);
        $raw = @file_get_contents($dir . '/meta.json');
        $meta = $raw ? json_decode($raw, true) : null;
        if (!is_array($meta) || empty($meta['chunks'])) {
            throw new \RuntimeException('上传会话已损坏，请重新上传');
        }
        return $meta;
    }

    /** 文件头 1MB 内出现 moov，说明已经 faststart，可以直接播 */
    private function hasFastStart(string $file): bool
    {
        $fh = fopen($file, 'rb');
        if (!$fh) return false;
        $head = fread($fh, 1024 * 1024);
        fclose($fh);
        $moov = strpos((string) $head, 'moov');
        $mdat = strpos((string) $head, 'mdat');
        return $moov !== false && ($mdat === false || $moov < $mdat);
    }

    private function isMp4Container(string $file): bool
    {
        $fh = fopen($file, 'rb');
        if (!$fh) return false;
        $head = fread($fh, 12);
        fclose($fh);
        return strlen((string) $head) >= 12 && substr($head, 4, 4) === 'ftyp';
    }

    private function remux(string $in, string $out): void
    {
        $cmd = sprintf(
            'timeout 1800 ffmpeg -y -hide_banner -loglevel error -i %s -c copy -movflags +faststart -f mp4 %s 2>&1',
            escapeshellarg($in),
            escapeshellarg($out)
        );
        $output = [];
        $rc     = 0;
        exec($cmd, $output, $rc);
        if ($rc !== 0 || !is_file($out) || filesize($out) < 1024) {
            \think\facade\Log::error('video remux failed: ' . implode(' | ', array_slice($output, -5)));
            throw new \RuntimeException('视频处理失败（ffmpeg 返回 ' . $rc . '），请换一个 mp4 文件重试');
        }
    }

    /** @return array{width:int,height:int,duration:int,codec:string} */
    private function probe(string $file): array
    {
        $cmd = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height,codec_name '
            . '-show_entries format=duration -of json %s 2>/dev/null',
            escapeshellarg($file)
        );
        $out = (string) shell_exec($cmd);
        $json = json_decode($out, true) ?: [];

        $stream = $json['streams'][0] ?? [];
        $dur    = (int) round((float) ($json['format']['duration'] ?? 0));

        return [
            'width'    => (int) ($stream['width'] ?? 0),
            'height'   => (int) ($stream['height'] ?? 0),
            'duration' => $dur,
            'codec'    => (string) ($stream['codec_name'] ?? ''),
        ];
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($dir);
    }

    private function human(int $bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . $u[$i];
    }
}
