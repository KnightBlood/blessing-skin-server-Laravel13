<?php

namespace Tests;

use App\Models\User;
use App\Services\Facades\Option;

class UpdateSourcesTest extends TestCase
{
    private string $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->superAdmin()->create(['locale' => 'zh_CN']));
        $this->environment = tempnam(sys_get_temp_dir(), 'bs-env-test-');
        file_put_contents($this->environment, "# retained\nAPP_NAME=Test\nUPDATE_SOURCE=https://old.example.test/update.json\nPLUGINS_REGISTRY=https://old.example.test/registry.json\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($this->environment));
        $this->app->instance(\App\Services\UpdateSources::class, new class($this->environment) extends \App\Services\UpdateSources {
            public function __construct(private string $path)
            {
            }

            protected function environmentPath(): string
            {
                return $this->path;
            }
        });
    }

    public function testSourcesRenderBelowRecaptchaAndSave()
    {
        $response = $this->get('/admin/options?lang=zh_CN')->assertOk()->assertSee('主站更新检测 URL')->assertSee('插件仓库 URL')->assertSee('card card-danger')->assertSee('text-danger font-weight-bold')->assertSee('请注意，修改更新 URL 可能导致您的 Blessing Skin 被恶意代码注入')->assertSee('请一定要知道你在做什么！');
        $this->assertLessThan(strpos($response->getContent(), 'name="update_source"'), strpos($response->getContent(), '请注意，修改更新 URL'));
        $this->assertLessThan(strpos($response->getContent(), 'submit_updates'), strpos($response->getContent(), 'submit_recaptcha'));
        if ($directory = getenv('OPTIONS_UI_PREVIEW_DIR')) {
            file_put_contents($directory.'/options.html', $response->getContent());
        }
        $this->post('/admin/options', ['option' => 'updates', 'update_source' => 'https://updates.example.test/update.json', 'plugins_registry' => 'https://plugins.example.test/registry_{lang}.json'])->assertOk()->assertSee('https://updates.example.test/update.json');
        $this->assertSame('https://updates.example.test/update.json', option('update_source'));
        $this->assertSame('https://plugins.example.test/registry_{lang}.json', option('plugins_registry'));
        $environment = \Dotenv\Dotenv::parse(file_get_contents($this->environment));
        $this->assertSame(option('update_source'), $environment['UPDATE_SOURCE']);
        $this->assertSame(option('plugins_registry'), $environment['PLUGINS_REGISTRY']);
        $this->assertSame('Test', $environment['APP_NAME']);
    }

    public function testInvalidSourceDoesNotChangeEitherSetting()
    {
        Option::set('update_source', 'https://old.example.test/update.json');
        $this->from('/admin/options')->post('/admin/options', ['option' => 'updates', 'update_source' => 'file:///tmp/update.json', 'plugins_registry' => 'https://plugins.example.test/registry.json'])->assertRedirect('/admin/options')->assertSessionHasErrors('update_source');
        $this->assertSame('https://old.example.test/update.json', option('update_source'));
    }

    public function testPresetsSaveBothSourcesAndRenderButtons()
    {
        $this->get('/admin/options')->assertOk()->assertSee('切换到 bs-community 官方源')->assertSee('切换到 DreamCraft-Tech 第三方修补源')->assertDontSee('语言地址可使用');
        foreach (['official', 'patch'] as $preset) {
            $this->post('/admin/options', ['option' => 'updates', 'update_source_preset' => $preset, 'update_source' => 'file:///tmp/old.json'])->assertOk();
            foreach (config('updates.sources.'.$preset) as $key => $url) {
                $this->assertSame($url, option($key));
                $environment = \Dotenv\Dotenv::parse(file_get_contents($this->environment));
                $this->assertSame($url, $environment[$key === 'update_source' ? 'UPDATE_SOURCE' : 'PLUGINS_REGISTRY']);
            }
        }
    }

    public function testEnvironmentFailureDoesNotSaveOptions()
    {
        Option::set('update_source', 'https://old.example.test/update.json');
        unlink($this->environment);
        $this->from('/admin/options')->post('/admin/options', ['option' => 'updates', 'update_source_preset' => 'patch'])->assertRedirect('/admin/options')->assertSessionHasErrors('update_source');
        $this->assertSame('https://old.example.test/update.json', option('update_source'));
    }

    public function testEnvironmentEscapesVariablesAndPreservesOtherLines()
    {
        $url = 'https://updates.example.test/update.json?token=$TOKEN#fragment';
        $this->post('/admin/options', ['option' => 'updates', 'update_source' => $url, 'plugins_registry' => 'https://plugins.example.test/registry_{lang}.json'])->assertOk();
        $environment = \Dotenv\Dotenv::parse(file_get_contents($this->environment));
        $this->assertSame($url, $environment['UPDATE_SOURCE']);
        $this->assertStringContainsString('# retained', file_get_contents($this->environment));
    }

    public function testEnvironmentDeduplicatesKeysAndPreservesPermissionsAndCrLf()
    {
        file_put_contents($this->environment, "APP_NAME=Test\r\nexport UPDATE_SOURCE=https://old.example.test/a\r\nUPDATE_SOURCE=https://old.example.test/b\r\n# retained\r\n");
        chmod($this->environment, 0600);
        $this->post('/admin/options', ['option' => 'updates', 'update_source_preset' => 'patch'])->assertOk();
        $contents = file_get_contents($this->environment);
        $this->assertSame(1, preg_match_all('/^UPDATE_SOURCE=/m', $contents));
        $this->assertSame(1, preg_match_all('/^PLUGINS_REGISTRY=/m', $contents));
        $this->assertStringContainsString("APP_NAME=Test\r\n", $contents);
        // Windows NTFS 不保存 POSIX 权限位，chmod() 后 fileperms() 恒为 0666/0444，
        // 该断言仅在类 Unix 平台有意义。
        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $this->environment);
            $this->assertSame(0600, fileperms($this->environment) & 0777);
        }
    }

    public function testEmptySourcesUseConfiguredDefaults()
    {
        $this->post('/admin/options', ['option' => 'updates', 'update_source' => '', 'plugins_registry' => ''])->assertOk()->assertSee(config('app.update_source'))->assertSee(config('plugins.registry'));
        $environment = \Dotenv\Dotenv::parse(file_get_contents($this->environment));
        $this->assertArrayNotHasKey('UPDATE_SOURCE', $environment);
        $this->assertArrayNotHasKey('PLUGINS_REGISTRY', $environment);
    }
}
