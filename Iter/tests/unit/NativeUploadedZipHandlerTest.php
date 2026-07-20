<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\NativeUploadedZipHandler;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class NativeUploadedZipHandlerTest extends CIUnitTestCase
{
    public function testThrowsWhenFileIsNotAGenuineUpload(): void
    {
        // is_uploaded_file() 은 실제 HTTP 업로드가 아니면 항상 false 를 반환하므로
        // isValid() 가 false 가 되는 경로를 검증한다(성공 경로는 실제 브라우저로 확인).
        $file = new UploadedFile('/nonexistent/tmp/path', 'takeout.zip', 'application/zip', 100, UPLOAD_ERR_OK);

        $handler = new NativeUploadedZipHandler();

        $this->expectException(RuntimeException::class);
        $handler->store($file, sys_get_temp_dir());
    }
}
