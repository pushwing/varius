<?php

declare(strict_types=1);

namespace Config;

use App\Models\OAuthTokenModel;
use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\AccountDeletionService;
use App\Services\Auth\GoogleTokenRevoker;
use App\Services\GooglePhotosAuthService;
use App\Services\Ingest\GdThumbnailGenerator;
use App\Services\Ingest\NativeExifReader;
use App\Services\Ingest\NativeUploadedZipHandler;
use App\Services\Ingest\PhotoExifParser;
use App\Services\Ingest\TakeoutMetadataParser;
use App\Services\Ingest\UploadedZipHandlerInterface;
use App\Services\PlainZipIngestService;
use App\Services\RouteVisualizationService;
use App\Services\StorageMaintenanceService;
use App\Services\TakeoutIngestService;
use CodeIgniter\Config\BaseService;
use League\OAuth2\Client\Provider\Google;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Google OAuth2 인증 서비스.
     *
     * 시크릿은 Config\GoogleOAuth(.env)에서만 로드하고, league Google 프로바이더·모델·
     * 암호화기를 조립해 GooglePhotosAuthService 를 구성한다.
     */
    public static function googlePhotosAuth(bool $getShared = true): GooglePhotosAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('googlePhotosAuth');
        }

        /** @var GoogleOAuth $config */
        $config = config(GoogleOAuth::class);

        $provider = new Google([
            'clientId' => $config->clientId,
            'clientSecret' => $config->clientSecret,
            'redirectUri' => $config->redirectUri,
            'scopes' => $config->scopes,
        ]);

        return new GooglePhotosAuthService(
            $provider,
            new OAuthTokenModel(),
            new UserModel(),
            static::encrypter(),
            60,
            new GoogleTokenRevoker(static::curlrequest()),
        );
    }

    /**
     * 사용자 데이터·계정 삭제 서비스.
     *
     * Google 토큰 폐기 + 좌표·토큰·사용자 트랜잭션 삭제 + 썸네일 디렉터리 삭제를 조립한다.
     */
    public static function accountDeletion(bool $getShared = true): AccountDeletionService
    {
        if ($getShared) {
            return static::getSharedInstance('accountDeletion');
        }

        return new AccountDeletionService(
            new PhotoLocationModel(),
            new OAuthTokenModel(),
            new UserModel(),
            static::googlePhotosAuth(),
            WRITEPATH . 'uploads/thumbnails',
        );
    }

    /**
     * 날짜별 동선 시각화 서비스.
     *
     * photo_locations 를 읽어 날짜별 그룹·색상·시간순 좌표로 조합한다.
     */
    public static function routeVisualization(bool $getShared = true): RouteVisualizationService
    {
        if ($getShared) {
            return static::getSharedInstance('routeVisualization');
        }

        return new RouteVisualizationService(new PhotoLocationModel());
    }

    /**
     * 업로드된 zip 파일 검증·저장 서비스.
     */
    public static function uploadedZipHandler(bool $getShared = true): UploadedZipHandlerInterface
    {
        if ($getShared) {
            return static::getSharedInstance('uploadedZipHandler');
        }

        return new NativeUploadedZipHandler();
    }

    /**
     * Google Takeout zip → 동선 좌표 적재 서비스.
     *
     * JSON 사이드카 파서 + 300px 썸네일 생성기를 조립한다.
     */
    public static function takeoutIngest(bool $getShared = true): TakeoutIngestService
    {
        if ($getShared) {
            return static::getSharedInstance('takeoutIngest');
        }

        return new TakeoutIngestService(
            new TakeoutMetadataParser(),
            new GdThumbnailGenerator(WRITEPATH . 'uploads/thumbnails'),
        );
    }

    /**
     * 일반 압축파일(사진 EXIF) → 동선 좌표 적재 서비스.
     *
     * EXIF 파서 + EXIF 리더 + 300px 썸네일 생성기를 조립한다.
     */
    public static function plainZipIngest(bool $getShared = true): PlainZipIngestService
    {
        if ($getShared) {
            return static::getSharedInstance('plainZipIngest');
        }

        return new PlainZipIngestService(
            new PhotoExifParser(),
            new NativeExifReader(),
            new GdThumbnailGenerator(WRITEPATH . 'uploads/thumbnails'),
        );
    }

    /**
     * 업로드 잔존물 정리 서비스(iter:cleanup 커맨드에서 사용).
     */
    public static function storageMaintenance(bool $getShared = true): StorageMaintenanceService
    {
        if ($getShared) {
            return static::getSharedInstance('storageMaintenance');
        }

        return new StorageMaintenanceService(
            new PhotoLocationModel(),
            WRITEPATH . 'uploads',
            WRITEPATH . 'uploads/thumbnails',
        );
    }
}
