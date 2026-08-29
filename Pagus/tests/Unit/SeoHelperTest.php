<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\SeoHelper;
use Config\Seo;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeoHelperTest extends TestCase
{
    private static function config(): Seo
    {
        /** @var Seo */
        return (new ReflectionClass(Seo::class))->newInstanceWithoutConstructor();
    }

    public function testHeadOutputsCoreMetaAndCanonical(): void
    {
        $config = self::config();
        $config->siteName = '파구스';
        $helper = new SeoHelper($config, 'https://pagus.example');

        $html = $helper->head([
            'title' => '파구스 — 파주 로컬 맛집 지도',
            'description' => '파주 맛집을 찾아보세요',
            'path' => '',
        ]);

        self::assertStringContainsString('<title>파구스 — 파주 로컬 맛집 지도</title>', $html);
        self::assertStringContainsString('<meta name="description" content="파주 맛집을 찾아보세요">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://pagus.example/">', $html);
        self::assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        self::assertStringContainsString('<meta property="og:site_name" content="파구스">', $html);
        self::assertStringContainsString('<meta property="og:locale" content="ko_KR">', $html);
    }

    public function testCanonicalJoinsPathWithoutDoubleSlash(): void
    {
        $helper = new SeoHelper(self::config(), 'https://pagus.example');

        $html = $helper->head(['title' => 't', 'description' => 'd', 'path' => 'restaurants/12']);

        self::assertStringContainsString('<link rel="canonical" href="https://pagus.example/restaurants/12">', $html);
        self::assertStringContainsString('<meta property="og:url" content="https://pagus.example/restaurants/12">', $html);
    }

    public function testNoindexSwitchesRobotsMeta(): void
    {
        $helper = new SeoHelper(self::config(), 'https://pagus.example');

        $html = $helper->head(['title' => 't', 'description' => 'd', 'path' => '', 'noindex' => true]);

        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
    }

    public function testImageOmittedWhenNoneProvidedAndNoDefaultConfigured(): void
    {
        $helper = new SeoHelper(self::config(), 'https://pagus.example');

        $html = $helper->head(['title' => 't', 'description' => 'd', 'path' => '']);

        self::assertStringNotContainsString('og:image', $html);
        self::assertStringContainsString('name="twitter:card" content="summary"', $html);
    }

    public function testImageProducesOgAndTwitterLargeCard(): void
    {
        $helper = new SeoHelper(self::config(), 'https://pagus.example');

        $html = $helper->head(['title' => 't', 'description' => 'd', 'path' => '', 'image' => 'https://pagus.example/photos/1']);

        self::assertStringContainsString('<meta property="og:image" content="https://pagus.example/photos/1">', $html);
        self::assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        self::assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
    }

    public function testFallsBackToConfiguredDefaultImage(): void
    {
        $config = self::config();
        $config->ogDefaultImage = 'https://pagus.example/assets/img/og-default.png';
        $helper = new SeoHelper($config, 'https://pagus.example');

        $html = $helper->head(['title' => 't', 'description' => 'd', 'path' => '']);

        self::assertStringContainsString('og:image" content="https://pagus.example/assets/img/og-default.png"', $html);
    }

    public function testVerificationMetaOmittedWhenNotConfigured(): void
    {
        $helper = new SeoHelper(self::config(), 'https://pagus.example');

        $html = $helper->head(['title' => 't', 'description' => 'd', 'path' => '']);

        self::assertStringNotContainsString('google-site-verification', $html);
        self::assertStringNotContainsString('msvalidate.01', $html);
    }

    public function testVerificationMetaRenderedWhenConfigured(): void
    {
        $config = self::config();
        $config->googleVerify = 'google-token';
        $config->bingVerify = 'bing-token';
        $helper = new SeoHelper($config, 'https://pagus.example');

        $html = $helper->head(['title' => 't', 'description' => 'd', 'path' => '']);

        self::assertStringContainsString('<meta name="google-site-verification" content="google-token">', $html);
        self::assertStringContainsString('<meta name="msvalidate.01" content="bing-token">', $html);
    }

    public function testOutputIsEscaped(): void
    {
        $helper = new SeoHelper(self::config(), 'https://pagus.example');

        $html = $helper->head(['title' => '<script>alert(1)</script>', 'description' => 'd', 'path' => '']);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
