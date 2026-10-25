<?php

namespace App\Http\Controllers;

use App\Services\Unzip;
use App\Services\UpdatePackage;
use Cache;
use Composer\CaBundle\CaBundle;
use Composer\Semver\Comparator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class UpdateController extends Controller
{
    public const SPEC = 2;

    public function showUpdatePage()
    {
        $info = $this->getUpdateInfo();
        $canUpdate = $this->canUpdate(Arr::get($info, 'info'));

        return view('admin.update', [
            'info' => [
                'latest' => Arr::get($info, 'info.latest'),
                'current' => config('app.version'),
            ],
            'error' => Arr::get($info, 'error', $canUpdate['reason']),
            'can_update' => $canUpdate['can'],
            'package' => session('system_update_package'),
            'patch_releases' => collect(config('patch.releases', []))->sort(fn ($a, $b) => version_compare($b['version'], $a['version']))->values()->all(),
            'upload_limits' => app(\App\Services\UploadLimits::class)->current(),
        ]);
    }

    public function download(Request $request, Unzip $unzip, Filesystem $filesystem, UpdatePackage $packages)
    {
        $info = $this->getUpdateInfo();
        if (!$info['ok'] || !$this->canUpdate($info['info'])['can']) {
            return json(trans('admin.update.info.up-to-date'), 1);
        }

        $info = $info['info'];
        $path = tempnam(sys_get_temp_dir(), 'bs');

        $response = Http::withOptions([
            'sink' => $path,
            'verify' => CaBundle::getSystemCaRootBundlePath(),
        ])->get($info['url']);

        if ($response->ok()) {
            if (Arr::get($info, 'package') === 'full') {
                try {
                    $checksum = Arr::get($info, 'sha256', '');
                    if (!preg_match('/\A[a-f0-9]{64}\z/D', $checksum) || !hash_equals($checksum, hash_file('sha256', $path))) {
                        throw new \RuntimeException('运行包校验值与更新信息不一致，请重新检查更新。');
                    }
                    $pending = $packages->prepare($path);
                    if ($pending['version'] !== $info['latest']) {
                        throw new \RuntimeException('运行包版本与更新信息不一致。');
                    }
                    $request->session()->put('system_update_package', $pending);

                    return json('运行包已下载并校验，请确认后安装。', 0, ['review' => true]);
                } catch (\RuntimeException $e) {
                    return json($e->getMessage(), 1);
                } finally {
                    @unlink($path);
                }
            }
            $unzip->extract($path, base_path());

            // Delete options cache. This allows us to update the version.
            $filesystem->delete(storage_path('options.php'));

            return json(trans('admin.update.complete'), 0);
        } else {
            return json(trans('admin.download.errors.download', ['error' => $response->status()]), 1);
        }
    }

    public function upload(Request $request, UpdatePackage $packages)
    {
        $max = (int) ceil(app(\App\Services\UploadLimits::class)->applicationBytes() / 1024);
        $request->validate(['package' => 'required|file|max:'.$max]);
        try {
            $file = $request->file('package');
            if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
                throw new \RuntimeException('请选择 ZIP 运行包。');
            }
            $info = $packages->prepare($file->getPathname());
            $request->session()->put('system_update_package', $info);

            return redirect()->route('admin.update.view')->with('update_notice', '运行包校验完成，请确认版本与校验值后安装。');
        } catch (\Throwable $e) {
            return redirect()->route('admin.update.view')->with('update_error', $e instanceof \RuntimeException ? $e->getMessage() : '运行包处理失败，请检查文件和目录权限。');
        }
    }

    public function install(Request $request, UpdatePackage $packages, Filesystem $filesystem)
    {
        $input = $request->validate(['id' => 'required|string|size:32']);
        $pending = $request->session()->get('system_update_package');
        abort_unless($pending && hash_equals($pending['id'], $input['id']), 403);
        try {
            $packages->install($input['id']);
            $filesystem->delete(storage_path('options.php'));
            $filesystem->delete(app()->getCachedConfigPath());
            $filesystem->delete(app()->getCachedRoutesPath());
            Artisan::call('view:clear');
            Artisan::call('twig:clean');
            $request->session()->forget('system_update_package');

            return redirect()->route('admin.update.view')->with('update_notice', '系统程序已更新，版本检查与数据库升级将在后续请求中完成。');
        } catch (\Throwable $e) {
            return redirect()->route('admin.update.view')->with('update_error', $e instanceof \RuntimeException ? $e->getMessage() : '安装失败，请检查站点目录权限与运行日志。');
        }
    }

    protected function getUpdateInfo()
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withOptions([
                'verify' => CaBundle::getSystemCaRootBundlePath(),
            ])->get(option('update_source') ?: config('app.update_source'));

            if ($response->ok()) {
                $info = $response->json();
                if (Arr::get($info, 'spec') === self::SPEC) {
                    return ['ok' => true, 'info' => $info];
                } else {
                    return ['ok' => false, 'error' => trans('admin.update.errors.spec')];
                }
            } else {
                return ['ok' => false, 'error' => 'HTTP status code: '.$response->status()];
            }
        } catch (\Throwable) {
            return ['ok' => false, 'error' => '更新源连接失败'];
        }
    }

    protected function canUpdate($info = [])
    {
        if (!is_array($info) || !isset($info['php'], $info['latest'])) {
            return ['can' => false, 'reason' => ''];
        }
        $php = Arr::get($info, 'php');
        preg_match('/(\d+\.\d+\.\d+)/', PHP_VERSION, $matches);
        $version = $matches[1];
        if (Comparator::lessThan($version, $php)) {
            return [
                'can' => false,
                'reason' => trans('admin.update.errors.php', ['version' => $php]),
            ];
        }

        $can = Comparator::greaterThan(Arr::get($info, 'latest'), config('app.version'));

        return ['can' => $can, 'reason' => ''];
    }
}
