<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RestaurantReviewService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RestaurantReviewServiceTest extends TestCase
{
    public function testBlankNicknameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantReviewService::assertReviewData(['nickname' => '', 'rating' => 5, 'content' => '좋았습니다.']);
    }

    public function testContentOver2000CharactersIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantReviewService::assertReviewData(['nickname' => '파구스', 'rating' => 5, 'content' => str_repeat('가', 2001)]);
    }

    public function testRatingOutsideRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantReviewService::assertReviewData(['nickname' => '파구스', 'rating' => 6, 'content' => '좋았습니다.']);
    }

    public function testValidReviewDataIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        RestaurantReviewService::assertReviewData(['nickname' => '파구스', 'rating' => 5, 'content' => '좋았습니다.']);
    }
}
