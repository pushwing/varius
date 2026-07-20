<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * 업로드된 zip 파일을 검증하고 로컬 경로로 저장한다.
 *
 * CI4 UploadedFile::isValid()/move() 는 PHP is_uploaded_file() 에 의존해 PHPUnit
 * 테스트에서 성공 경로를 시뮬레이션할 수 없다 — 이 인터페이스로 분리해 컨트롤러
 * 테스트에서는 mock 으로 대체하고, 실제 업로드 성공 경로는 브라우저로 수동 확인한다.
 */
interface UploadedZipHandlerInterface
{
    /**
     * @return string 저장된 zip 파일의 로컬 절대경로
     */
    public function store(UploadedFile $file, string $destinationDir): string;
}
