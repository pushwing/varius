<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RestaurantPhotoService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RestaurantPhotoServiceTest extends TestCase
{
    public function testValidJpegIsAcceptedAndReturnsExtension(): void
    {
        self::assertSame('jpg', RestaurantPhotoService::assertPhotoUpload('image/jpeg', 'jpg', 1024));
    }

    public function testValidJpegExtensionAliasIsAccepted(): void
    {
        self::assertSame('jpg', RestaurantPhotoService::assertPhotoUpload('image/jpeg', 'jpeg', 1024));
    }

    public function testBoundarySizeIsAccepted(): void
    {
        self::assertSame('png', RestaurantPhotoService::assertPhotoUpload('image/png', 'png', 5 * 1024 * 1024));
    }

    public function testOversizedFileIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantPhotoService::assertPhotoUpload('image/jpeg', 'jpg', 5 * 1024 * 1024 + 1);
    }

    public function testDisallowedMimeTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantPhotoService::assertPhotoUpload('application/x-php', 'jpg', 1024);
    }

    public function testMismatchedExtensionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantPhotoService::assertPhotoUpload('image/jpeg', 'txt', 1024);
    }

    public function testDisallowedExtensionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantPhotoService::assertPhotoUpload('image/gif', 'gif', 1024);
    }

    public function testServerSizeLimitErrorIsExplained(): void
    {
        self::assertSame('파일 크기가 서버 허용 한도를 초과했습니다.', RestaurantPhotoService::uploadErrorMessage(UPLOAD_ERR_INI_SIZE));
    }

    public function testUnknownUploadErrorFallsBackToGenericMessage(): void
    {
        self::assertSame('파일을 업로드하지 못했습니다.', RestaurantPhotoService::uploadErrorMessage(UPLOAD_ERR_CANT_WRITE));
    }

    public function testPhotoOfAnotherRestaurantIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantPhotoService::assertPhotoOwnership(['id' => 5, 'restaurant_id' => 2], 1);
    }

    public function testMissingPhotoIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RestaurantPhotoService::assertPhotoOwnership(null, 1);
    }

    public function testOwnPhotoIsAccepted(): void
    {
        $photo = ['id' => 5, 'restaurant_id' => 1];
        self::assertSame($photo, RestaurantPhotoService::assertPhotoOwnership($photo, 1));
    }
}
