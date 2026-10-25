<?php
// Exercise the same package preparation code used by the site's ZIP updater.
require dirname(__DIR__).'/vendor/autoload.php';
chdir(dirname(__DIR__));
putenv('APP_ENV=testing');$_ENV['APP_ENV']='testing';$_SERVER['APP_ENV']='testing';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
$info=app(App\Services\UpdatePackage::class)->prepare(realpath($argv[1]));
$expected=json_decode(file_get_contents('package.json'),true)['version'];
if ($info['version']!==$expected || $info['sha256']!==hash_file('sha256',$argv[1]) || $info['count']<1000) {throw new RuntimeException('Incomplete upgrade runtime');}
(new Illuminate\Filesystem\Filesystem())->deleteDirectory(storage_path('app/system-updates/'.$info['id']));
echo 'ZIP updater accepted full runtime '.$info['version'].' ('.$info['count'].' files)'.PHP_EOL;
