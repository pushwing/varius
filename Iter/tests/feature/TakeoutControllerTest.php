<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use App\Services\Ingest\UploadedZipHandlerInterface;
use App\Services\TakeoutIngestService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;

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

    public function testUploadRejectsFileExceedingServerIniLimitWithClear413(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-takeout-3', 't3@example.com', 'T3');

        // PHP 가 upload_max_filesize 를 초과한 업로드를 감지하면 tmp_name 없이
        // error=UPLOAD_ERR_INI_SIZE 로 $_FILES 를 채운다 — 이 상태를 재현한다.
        service('superglobals')->setFilesArray([
            'file' => [
                'name' => 'takeout.zip',
                'type' => '',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ],
        ]);

        try {
            $result = $this->withSession(['user_id' => $userId])->post('takeout/upload');

            $result->assertStatus(413);
            $data = json_decode($result->getJSON() ?? '', true);
            $this->assertStringContainsString('너무 큽니다', $data['error']);
        } finally {
            service('superglobals')->setFilesArray([]);
        }
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
                'capped' => false,
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
            $this->assertFalse($data['capped']);
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

    public function testUploadReturnsCleanJsonErrorWhenSaveFails(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-takeout-4', 't4@example.com', 'T4');

        $handler = $this->createMock(UploadedZipHandlerInterface::class);
        $handler->method('store')->willReturn('/fake/takeout.zip');
        Services::injectMock('uploadedZipHandler', $handler);

        $ingest = $this->createMock(TakeoutIngestService::class);
        $ingest->method('ingest')->willReturn([
            'locations' => [new PhotoLocation('photo1.jpg', 37.5665, 126.9780, '2024-03-15 09:00:00')],
            'totalCandidates' => 1,
        ]);
        Services::injectMock('takeoutIngest', $ingest);

        // DB 저장 단계(예: 컬럼 불일치·제약조건 위반)가 실패하는 상황을 재현한다.
        $locationModel = $this->createMock(PhotoLocationModel::class);
        $locationModel->method('saveMany')->willThrowException(new RuntimeException('DB 저장 실패'));
        Factories::injectMock('models', PhotoLocationModel::class, $locationModel);

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

            $result->assertStatus(500);
            $data = json_decode($result->getJSON() ?? '', true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('error', $data);
        } finally {
            service('superglobals')->setFilesArray([]);
            if (is_file($fakeUpload)) {
                unlink($fakeUpload);
            }
        }
    }
}
