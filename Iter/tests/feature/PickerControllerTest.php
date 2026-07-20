<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\GooglePhotosAuthService;
use App\Services\Ingest\PhotoLocation;
use App\Services\PhotoIngestService;
use App\Services\PhotoPickerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * @internal
 */
final class PickerControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    /**
     * getValidAccessToken 을 스텁한 인증 서비스 목을 서비스 컨테이너에 주입한다.
     */
    private function injectAuth(string $token = 'ya29.token'): void
    {
        $auth = $this->createMock(GooglePhotosAuthService::class);
        $auth->method('getValidAccessToken')->willReturn($token);
        Services::injectMock('googlePhotosAuth', $auth);
    }

    private function injectPicker(PhotoPickerService $picker): void
    {
        Services::injectMock('photoPicker', $picker);
    }

    public function testCreateSessionRequiresLogin(): void
    {
        $result = $this->post('picker/sessions');

        $result->assertStatus(401);
    }

    public function testCreateSessionReturnsPickerUriAndStoresSessionId(): void
    {
        $this->injectAuth();
        $picker = $this->createMock(PhotoPickerService::class);
        $picker->expects($this->once())
            ->method('createSession')
            ->with('ya29.token')
            ->willReturn([
                'id' => 'sess-1',
                'pickerUri' => 'https://photos.google.com/picker/sess-1',
            ]);
        $this->injectPicker($picker);

        $result = $this->withSession(['user_id' => 1])->post('picker/sessions');

        $result->assertStatus(201);
        $result->assertJSONFragment([
            'sessionId' => 'sess-1',
            'pickerUri' => 'https://photos.google.com/picker/sess-1',
        ]);
        $this->assertSame('sess-1', session()->get('picker_session_id'));
    }

    public function testStatusRequiresLogin(): void
    {
        $result = $this->get('picker/sessions/status');

        $result->assertStatus(401);
    }

    public function testStatusReturns404WhenNoActiveSession(): void
    {
        $this->injectAuth();
        $result = $this->withSession(['user_id' => 1])->get('picker/sessions/status');

        $result->assertStatus(404);
    }

    public function testStatusReturnsMediaItemsSetFlag(): void
    {
        $this->injectAuth();
        $picker = $this->createMock(PhotoPickerService::class);
        $picker->expects($this->once())
            ->method('getSession')
            ->with('ya29.token', 'sess-1')
            ->willReturn(['id' => 'sess-1', 'mediaItemsSet' => true]);
        $this->injectPicker($picker);

        $result = $this->withSession(['user_id' => 1, 'picker_session_id' => 'sess-1'])
            ->get('picker/sessions/status');

        $result->assertStatus(200);
        $result->assertJSONFragment(['mediaItemsSet' => true]);
    }

    public function testItemsReturns409WhenSelectionNotReady(): void
    {
        $this->injectAuth();
        $picker = $this->createMock(PhotoPickerService::class);
        $picker->method('getSession')->willReturn(['id' => 'sess-1', 'mediaItemsSet' => false]);
        $picker->expects($this->never())->method('listPickedMediaItems');
        $this->injectPicker($picker);

        $result = $this->withSession(['user_id' => 1, 'picker_session_id' => 'sess-1'])
            ->get('picker/media-items');

        $result->assertStatus(409);
    }

    public function testItemsReturnsPickedMediaItemsWhenReady(): void
    {
        $this->injectAuth();
        $picker = $this->createMock(PhotoPickerService::class);
        $picker->method('getSession')->willReturn(['id' => 'sess-1', 'mediaItemsSet' => true]);
        $picker->expects($this->once())
            ->method('listPickedMediaItems')
            ->with('ya29.token', 'sess-1')
            ->willReturn([
                ['id' => 'item-1', 'baseUrl' => 'https://base/1'],
                ['id' => 'item-2', 'baseUrl' => 'https://base/2'],
            ]);
        $this->injectPicker($picker);

        $result = $this->withSession(['user_id' => 1, 'picker_session_id' => 'sess-1'])
            ->get('picker/media-items');

        $result->assertStatus(200);
        $result->assertJSONFragment([
            'mediaItems' => [
                ['id' => 'item-1', 'baseUrl' => 'https://base/1'],
                ['id' => 'item-2', 'baseUrl' => 'https://base/2'],
            ],
        ]);
    }

    public function testItemsRequiresLogin(): void
    {
        $result = $this->get('picker/media-items');

        $result->assertStatus(401);
    }

    public function testIngestRequiresLogin(): void
    {
        $result = $this->post('picker/ingest');

        $result->assertStatus(401);
    }

    public function testIngestSavesPickedLocationsAndReturnsCount(): void
    {
        // photo_locations.user_id FK 충족을 위해 사용자를 시드한다.
        $userId = (new UserModel())->upsertByGoogleSub('sub-ing', 'ing@example.com', 'Ing');

        $this->injectAuth();

        $picker = $this->createMock(PhotoPickerService::class);
        $picker->method('listPickedMediaItems')->willReturn([['id' => 'm1', 'baseUrl' => 'https://base/m1']]);
        $this->injectPicker($picker);

        $ingest = $this->createMock(PhotoIngestService::class);
        $ingest->expects($this->once())
            ->method('ingest')
            ->willReturn([new PhotoLocation('m1', 37.5, 127.0, '2024-03-15 09:00:00')]);
        Services::injectMock('photoIngest', $ingest);

        $result = $this->withSession(['user_id' => $userId, 'picker_session_id' => 'sess-1'])
            ->post('picker/ingest');

        $result->assertStatus(200);
        $result->assertJSONFragment(['saved' => 1]);
        $this->seeInDatabase('photo_locations', [
            'user_id' => $userId,
            'google_media_item_id' => 'm1',
        ]);
    }
}
