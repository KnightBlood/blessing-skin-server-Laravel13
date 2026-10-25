<?php

declare(strict_types=1);

namespace App\Services;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

class UpdatePackage
{
    protected function root(): string
    {
        return base_path();
    }

    protected function directory(string $id): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new RuntimeException('更新任务格式错误。');
        }

        return storage_path('app/system-updates/'.$id);
    }

    private function allowed(string $name): bool
    {
        $root = explode('/', $name)[0];
        if (in_array($root, ['app', 'config', 'database', 'resources', 'routes', 'vendor'], true)) {
            return true;
        }
        if ($root === 'bootstrap') {
            return !preg_match('#^bootstrap/cache(/|$)#', $name);
        }
        if ($root === 'public') {
            return !preg_match('#^public/(storage|uploads|textures|plugins)(/|$)#', $name);
        }

        return in_array($name, ['artisan', 'composer.json', 'composer.lock', 'package.json', 'index.html', 'LICENSE', 'README.md', 'README-zh.md', 'BUILD-PHP85.md', 'CHANGELOG-PATCH.md'], true);
    }

    public function prepare(string $file): array
    {
        $id = bin2hex(random_bytes(16));
        $dir = $this->directory($id);
        $fs = new Filesystem();
        $fs->makeDirectory($dir.'/payload', 0700, true);
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($file) !== true) {
                throw new RuntimeException('请选择有效的 ZIP 运行包。');
            }
            $opened = true;
            if ($zip->numFiles > 30000) {
                throw new RuntimeException('更新包文件数超过 30000。');
            }
            $names = [];
            $seen = [];
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#(^|/)(\.\.?)(/|$)|^[A-Za-z]:#', $name)) {
                    throw new RuntimeException('ZIP 文件路径格式错误。');
                }
                $key = strtolower(rtrim($name, '/'));
                if (isset($seen[$key])) {
                    throw new RuntimeException('ZIP 包含重复文件路径。');
                }
                $seen[$key] = true;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('更新包应使用普通文件和目录。');
                }
                $bytes += $stat['size'];
                if ($bytes > 1073741824) {
                    throw new RuntimeException('解压大小超过 1 GiB。');
                }
                $names[$name] = $stat['size'];
            }
            $prefix = null;
            foreach (array_keys($names) as $name) {
                if ($name === 'composer.json' || preg_match('#^[^/]+/composer\.json$#', $name)) {
                    $candidate = json_decode($zip->getFromName($name), true);
                    if (($candidate['name'] ?? '') === 'bs-community/blessing-skin-server') {
                        if ($prefix !== null) {
                            throw new RuntimeException('请上传单个系统运行包。');
                        }
                        $prefix = substr($name, 0, -strlen('composer.json'));
                        $composer = $candidate;
                    }
                }
            }
            if ($prefix === null) {
                throw new RuntimeException('未识别到 Blessing Skin 系统运行包。');
            }
            foreach (['artisan', 'bootstrap/app.php', 'config/app.php', 'public/index.php', 'vendor/autoload.php', 'resources/views/assets/app.twig'] as $required) {
                if (!isset($names[$prefix.$required])) {
                    throw new RuntimeException('运行包缺少 '.$required.'。');
                }
            }
            $compiled = false;
            foreach (array_keys($names) as $name) {
                if (str_starts_with($name, $prefix.'public/app/') && str_ends_with($name, '.js')) {
                    $compiled = true;
                    break;
                }
            }
            if (!$compiled) {
                throw new RuntimeException('运行包缺少已编译的前端资源。');
            }
            if (!Semver::satisfies(PHP_VERSION, $composer['require']['php'] ?? '*')) {
                throw new RuntimeException('当前 PHP 版本与运行包要求不匹配。');
            }
            $config = $zip->getFromName($prefix.'config/app.php');
            if (!preg_match('/[\'"]version[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $config, $match)) {
                throw new RuntimeException('无法读取运行包版本号。');
            }
            $version = $match[1];
            if (Comparator::lessThan($version, config('app.version'))) {
                throw new RuntimeException('请选择当前版本或更高版本的运行包。');
            }
            $files = [];
            foreach ($names as $name => $size) {
                if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
                    continue;
                }
                $relative = substr($name, strlen($prefix));
                if (!$this->allowed($relative)) {
                    continue;
                }
                $target = $dir.'/payload/'.$relative;
                $fs->ensureDirectoryExists(dirname($target), 0700);
                $input = $zip->getStream($name);
                $output = fopen($target, 'wb');
                if (!$input || !$output) {
                    throw new RuntimeException('无法读取更新文件。');
                }
                try {
                    $written = stream_copy_to_stream($input, $output);
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($written !== $size) {
                    throw new RuntimeException('更新文件解压不完整。');
                }
                $files[] = $relative;
            }
            $info = ['id' => $id, 'version' => $version, 'sha256' => hash_file('sha256', $file), 'files' => $files, 'count' => count($files), 'created_at' => time()];
            $fs->put($dir.'/package.json', json_encode($info, JSON_THROW_ON_ERROR));

            return $info;
        } catch (\Throwable $e) {
            $fs->deleteDirectory($dir);
            throw $e;
        } finally {
            if ($opened) {
                $zip->close();
            }
        }
    }

    public function install(string $id): array
    {
        $dir = $this->directory($id);
        $fs = new Filesystem();
        $info = is_file($dir.'/package.json') ? json_decode(file_get_contents($dir.'/package.json'), true) : null;
        if (!$info || $info['created_at'] < time() - 3600 || is_file($dir.'/installed.json')) {
            throw new RuntimeException('更新任务已过期或已安装，请重新上传运行包。');
        }
        if (Comparator::lessThan($info['version'], config('app.version'))) {
            throw new RuntimeException('请选择当前版本或更高版本的运行包。');
        }
        $lock = fopen(storage_path('app/system-updates/install.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }throw new RuntimeException('系统更新正在执行，请稍后重试。');
        }
        $changed = [];
        try {
            if (is_file($dir.'/installed.json')) {
                throw new RuntimeException('更新任务已安装，请重新上传运行包。');
            }
            foreach ($info['files'] as $name) {
                $path = $this->root();
                foreach (explode('/', $name) as $segment) {
                    $path .= '/'.$segment;
                    if (is_link($path)) {
                        throw new RuntimeException('更新目标路径包含符号链接：'.$name);
                    }
                }
                $ancestor = file_exists($path) ? $path : dirname($path);
                while (!file_exists($ancestor)) {
                    $ancestor = dirname($ancestor);
                }
                if (!is_writable($ancestor) || (file_exists($path) && !is_file($path))) {
                    throw new RuntimeException('更新目标目录或文件不可写：'.$name);
                }
            }
            foreach ($info['files'] as $name) {
                $target = $this->root().'/'.$name;
                $backup = $dir.'/backup/'.$name;
                $exists = is_file($target);
                if ($exists) {
                    $fs->ensureDirectoryExists(dirname($backup), 0700);
                    if (!copy($target, $backup)) {
                        throw new RuntimeException('无法备份更新文件。');
                    }
                }
                $fs->ensureDirectoryExists(dirname($target));
                $this->publish($dir.'/payload/'.$name, $target);
                $changed[$name] = $exists;
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($target, true);
                }
            }
            $fs->put($dir.'/installed.json', json_encode(['version' => $info['version'], 'installed_at' => time()]));

            return $info;
        } catch (\Throwable $e) {
            foreach (array_reverse($changed, true) as $name => $exists) {
                $target = $this->root().'/'.$name;
                if ($exists) {
                    copy($dir.'/backup/'.$name, $target);
                } else {
                    $fs->delete($target);
                }
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($target, true);
                }
            }
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function publish(string $source, string $target): void
    {
        $tmp = tempnam(dirname($target), '.bs-update-');
        try {
            if (!copy($source, $tmp)) {
                throw new RuntimeException('无法写入更新文件。');
            }
            chmod($tmp, is_file($target) ? (fileperms($target) & 0777) : 0644);
            if (!rename($tmp, $target)) {
                throw new RuntimeException('无法替换更新文件。');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
