<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class UpdateSources
{
    protected function environmentPath(): string
    {
        return app()->environmentFilePath();
    }

    public function save(array $sources): void
    {
        $fs = new Filesystem();
        $path = $this->environmentPath();
        if (!is_file($path) || !is_readable($path) || !is_writable($path) || !is_writable(dirname(realpath($path)))) {
            throw new RuntimeException('请确认站点 .env 文件及所在目录可写，再保存更新源。');
        }
        $fs->ensureDirectoryExists(storage_path('app'));
        $lock = fopen(storage_path('app/update-sources.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('更新源正在保存，请稍后重试。');
        }
        try {
            $values = [
                'UPDATE_SOURCE' => (string) ($sources['update_source'] ?? ''),
                'PLUGINS_REGISTRY' => (string) ($sources['plugins_registry'] ?? ''),
            ];
            foreach ($values as $value) {
                if (preg_match('/[\r\n\x00]/', $value)) {
                    throw new RuntimeException('更新源地址应为单行 URL。');
                }
            }
            $contents = $fs->get($path);
            $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
            $lines = preg_split('/\r\n|\n|\r/', $contents);
            $result = [];
            $seen = [];
            foreach ($lines as $line) {
                if (preg_match('/^\s*(?:export\s+)?(UPDATE_SOURCE|PLUGINS_REGISTRY)\s*=/', $line, $match)) {
                    $key = $match[1];
                    if (!isset($seen[$key]) && $values[$key] !== '') {
                        $result[] = $key.'="'.addcslashes($values[$key], '\\"$').'"';
                    }
                    $seen[$key] = true;
                } else {
                    $result[] = $line;
                }
            }
            foreach ($values as $key => $value) {
                if (!isset($seen[$key]) && $value !== '') {
                    $result[] = $key.'="'.addcslashes($value, '\\"$').'"';
                }
            }
            $cache = app()->getCachedConfigPath();
            if (is_file($cache) && !$fs->delete($cache)) {
                throw new RuntimeException('请确认配置缓存目录可写，再保存更新源。');
            }
            $fs->replace($path, implode($newline, $result), fileperms($path) & 0777);
            config([
                'app.update_source' => $values['UPDATE_SOURCE'] ?: config('updates.sources.patch.update_source'),
                'plugins.registry' => $values['PLUGINS_REGISTRY'] ?: config('updates.sources.patch.plugins_registry'),
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
