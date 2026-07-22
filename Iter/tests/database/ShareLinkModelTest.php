<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\ShareLinkModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ShareLinkModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace = 'App';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserModel())->upsertByGoogleSub('sub-share', 'share@example.com', 'Share');
    }

    public function testCreateOrGetGeneratesUnguessableToken(): void
    {
        $token = (new ShareLinkModel())->createOrGet($this->userId, '2024-03-15');

        // 무작위 32자 hex — 추측 불가한 토큰이어야 한다.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function testCreateOrGetReturnsSameTokenForSameUserAndDate(): void
    {
        $model = new ShareLinkModel();
        $first = $model->createOrGet($this->userId, '2024-03-15');
        $second = $model->createOrGet($this->userId, '2024-03-15');

        // 같은 날짜를 다시 공유해도 기존 링크가 유지돼야 한다(링크 재발급 방지).
        $this->assertSame($first, $second);
        $this->assertSame(1, $model->where('user_id', $this->userId)->countAllResults());
    }

    public function testDifferentDatesGetDifferentTokens(): void
    {
        $model = new ShareLinkModel();

        $this->assertNotSame(
            $model->createOrGet($this->userId, '2024-03-15'),
            $model->createOrGet($this->userId, '2024-03-16'),
        );
    }

    public function testFindByTokenReturnsOwnerAndDate(): void
    {
        $model = new ShareLinkModel();
        $token = $model->createOrGet($this->userId, '2024-03-15');

        $share = $model->findByToken($token);

        $this->assertNotNull($share);
        $this->assertSame($this->userId, (int) $share['user_id']);
        $this->assertSame('2024-03-15', $share['share_date']);
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull((new ShareLinkModel())->findByToken(str_repeat('0', 32)));
    }
}
