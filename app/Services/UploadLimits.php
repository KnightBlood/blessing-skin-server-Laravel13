<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadLimits
{
    public function applicationBytes(): int
    {
        return max(1, (int) config('updates.max_upload_mb', 256)) * 1048576;
    }

    public function current(): array
    {
        $php = UploadedFile::getMaxFilesize();
        $app = $this->applicationBytes();

        return ['effective' => $this->format(min($php, $app)), 'php_upload' => (string) ini_get('upload_max_filesize'),
            'php_post' => (string) ini_get('post_max_size'), 'application' => $this->format($app),
            'enabled' => filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN)];
    }

    private function format(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 2, '.', ''), '0'), '.').' MiB';
    }
}
