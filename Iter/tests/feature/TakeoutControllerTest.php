<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\UploadedZipHandlerInterface;
use App\Services\TakeoutIngestService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * @internal
 */
final class TakeoutControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean(); // 레이트 리밋 throttler 상태 초기화(테스트 격리)
    }

    public function testUploadRequiresLogin(): void
    {
        $result = $this->post('takeout/upload');

        $result->assertStatus(401);
    }

    public function testUploadRejectsMissingFile(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-takeout-1', 't1@example.com', 'T1');

        $result = $this->withSession(['user_id' => $userId])->post('takeout/upload');

        $result->assertStatus(422);
    }

    public function testUploadSavesLocationsAndReturnsCount(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-takeout-2', 't2@example.com', 'T2');

        $handler = $this->createMock(UploadedZipHandlerInterface::class);
        $handler->method('store')->willReturn('/fake/takeout.zip');
        Services::injectMock('uploadedZipHandler', $handler);

        $ingest = $this->createMock(TakeoutIngestService::class);
        $ingest->expects($this->once())
            ->method('ingest')
            ->with('/fake/takeout.zip')
            ->willReturn([
                'locations' => [new PhotoLocation('photo1.jpg', 37.5665, 126.9780, '2024-03-15 09:00:00')],
                'totalCandidates' => 1,
            ]);
        Services::injectMock('takeoutIngest', $ingest);

        // 컨트롤러가 $this->request->getFile('file') 로 실제 UploadedFile 을 요구하므로
        // superglobals 에 직접 파일 정보를 주입한다(실제 tmp_name 은 진짜 파일을 가리켜야 함).
        $fakeUpload = tempnam(sys_get_temp_dir(), 'upload_') . '.zip';
        file_put_contents($fakeUpload, 'zip-bytes');
        service('superglobals')->setFilesArray([
            'file' => [
                'name' => 'takeout.zip',
                'type' => 'application/zip',
                'tmp_name' => $fakeUpload,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($fakeUpload),
            ],
        ]);

        try {
            $result = $this->withSession(['user_id' => $userId])->post('takeout/upload');

            $result->assertStatus(200);
            $data = json_decode($result->getJSON() ?? '', true);
            $this->assertSame(1, $data['saved']);
            $this->assertSame(1, $data['totalCandidates']);
            $this->seeInDatabase('photo_locations', [
                'user_id' => $userId,
                'source_item_id' => 'photo1.jpg',
            ]);
        } finally {
            service('superglobals')->setFilesArray([]);
            if (is_file($fakeUpload)) {
                unlink($fakeUpload);
            }
        }
    }
}
