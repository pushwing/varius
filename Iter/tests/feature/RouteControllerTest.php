<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\Ingest\PhotoLocation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class RouteControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    public function testRoutesRequiresLogin(): void
    {
        $result = $this->get('routes');

        $result->assertStatus(401);
    }

    public function testRoutesReturnsGroupedRoutesJson(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-route', 'route@example.com', 'Route');
        (new PhotoLocationModel())->saveMany($userId, [
            new PhotoLocation('m1', 37.5, 127.0, '2024-03-15 09:00:00'),
            new PhotoLocation('m2', 37.6, 127.1, '2024-03-15 12:00:00'),
            new PhotoLocation('m3', 35.1, 129.0, '2024-03-16 08:00:00'),
        ]);

        $result = $this->withSession(['user_id' => $userId])->get('routes');

        $result->assertStatus(200);

        $data = json_decode($result->getJSON() ?? '', true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data['dates']);
        $this->assertSame('2024-03-15', $data['dates'][0]['date']);
        $this->assertCount(2, $data['dates'][0]['points']);
        $this->assertSame('m1', $data['dates'][0]['points'][0]['media_item_id']);
    }

    public function testMapRedirectsWhenNotLoggedIn(): void
    {
        $result = $this->get('map');

        $result->assertRedirect();
    }

    public function testMapRendersLeafletPageWhenLoggedIn(): void
    {
        $userId = (new UserModel())->upsertByGoogleSub('sub-map', 'map@example.com', 'Map');

        $result = $this->withSession(['user_id' => $userId])->get('map');

        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('id="map"', $body);
        // Leaflet 자산을 로드해야 한다.
        $this->assertStringContainsString('leaflet', $body);
    }
}
