<?php

declare(strict_types=1);

namespace Config;

use App\Models\OAuthTokenModel;
use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\GoogleApiUsageTracker;
use App\Services\GooglePhotosAuthService;
use App\Services\Ingest\CurlMultiDownloader;
use App\Services\Ingest\ExifToolExtractor;
use App\Services\Ingest\FallbackExifExtractor;
use App\Services\Ingest\GdThumbnailGenerator;
use App\Services\Ingest\NativeExifExtractor;
use App\Services\PhotoIngestService;
use App\Services\PhotoPickerService;
use App\Services\RouteVisualizationService;
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
        );
    }

    /**
     * Google Photos Picker 세션 서비스.
     *
     * CI4 내장 CURLRequest 를 주입해 Picker REST API(세션 생성·폴링·목록 조회)를 호출한다.
     * 요청당 최대 매수는 10장으로 고정한다.
     */
    public static function photoPicker(bool $getShared = true): PhotoPickerService
    {
        if ($getShared) {
            return static::getSharedInstance('photoPicker');
        }

        return new PhotoPickerService(static::curlrequest(), 10, null, 60, static::googleApiUsageTracker());
    }

    /**
     * 사진 원본 → EXIF 좌표 추출 서비스.
     *
     * curl_multi 병렬 다운로더 + (네이티브 → exiftool 폴백) EXIF 추출기 + 300px 썸네일 생성기를 조립한다.
     * HEIC 등 네이티브가 못 읽는 포맷은 exiftool 바이너리(shell_exec)로 재시도한다.
     */
    public static function photoIngest(bool $getShared = true): PhotoIngestService
    {
        if ($getShared) {
            return static::getSharedInstance('photoIngest');
        }

        $extractor = new FallbackExifExtractor(new NativeExifExtractor(), new ExifToolExtractor());
        $thumbnailer = new GdThumbnailGenerator(WRITEPATH . 'uploads/thumbnails');
        $downloader = new CurlMultiDownloader(usageTracker: static::googleApiUsageTracker());

        return new PhotoIngestService($downloader, $extractor, 200.0, $thumbnailer);
    }

    /**
     * Google API 쿼터 사용량 추적기.
     *
     * PhotoPickerService·CurlMultiDownloader 의 호출마다 일 단위 카운터를 남겨
     * 프로젝트 쿼터 소진 속도를 로그로 모니터링할 수 있게 한다.
     */
    public static function googleApiUsageTracker(bool $getShared = true): GoogleApiUsageTracker
    {
        if ($getShared) {
            return static::getSharedInstance('googleApiUsageTracker');
        }

        return new GoogleApiUsageTracker(static::cache());
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
}
