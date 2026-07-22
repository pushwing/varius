<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\TripModel;
use App\Models\TripShareLinkModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TripShareLinkModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();
        $userId = (new UserModel())->upsertByGoogleSub('sub-tripshare', 'tripshare@example.com', 'TripShare');
        $this->tripId = (int) (new TripModel())->insert([
            'user_id' => $userId, 'title' => 'T', 'body' => '',
            'start_date' => '2024-03-15', 'end_date' => '2024-03-17', 'cover_photo_id' => null,
        ]);
    }

    public function testCreateOrGetGeneratesUnguessableToken(): void
    {
        $token = (new TripShareLinkModel())->createOrGet($this->tripId);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function testCreateOrGetReturnsSameTokenForSameTrip(): void
    {
        $model = new TripShareLinkModel();
        $first = $model->createOrGet($this->tripId);
        $second = $model->createOrGet($this->tripId);

        $this->assertSame($first, $second);
        $this->assertSame(1, $model->where('trip_id', $this->tripId)->countAllResults());
    }

    public function testFindByTokenReturnsTripId(): void
    {
        $model = new TripShareLinkModel();
        $token = $model->createOrGet($this->tripId);

        $this->assertSame($this->tripId, $model->findByToken($token));
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull((new TripShareLinkModel())->findByToken(str_repeat('0', 32)));
    }

    public function testShareLinkIsRemovedWhenTripDeletedViaForeignKey(): void
    {
        $model = new TripShareLinkModel();
        $token = $model->createOrGet($this->tripId);

        (new TripModel())->delete($this->tripId);

        $this->assertNull($model->findByToken($token));
    }
}
