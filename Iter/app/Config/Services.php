<?php

declare(strict_types=1);

namespace Config;

use App\Models\OAuthTokenModel;
use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Services\GooglePhotosAuthService;
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
