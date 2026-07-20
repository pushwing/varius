<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

class NativeUploadedZipHandler implements UploadedZipHandlerInterface
{
    public function store(UploadedFile $file, string $destinationDir): string
    {
        if (! $file->isValid()) {
            throw new RuntimeException('업로드된 파일이 유효하지 않습니다: ' . $file->getErrorString());
        }

        $name = $file->getRandomName();
        $file->move($destinationDir, $name);

        return rtrim($destinationDir, '/') . '/' . $name;
    }
}
