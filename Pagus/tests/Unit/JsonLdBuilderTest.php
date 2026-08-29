<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Seo\JsonLdBuilder;
use Config\Seo;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class JsonLdBuilderTest extends TestCase
{
    private static function config(): Seo
    {
        /** @var Seo */
        return (new ReflectionClass(Seo::class))->newInstanceWithoutConstructor();
    }

    private static function builder(): JsonLdBuilder
    {
        return new JsonLdBuilder(self::config(), 'https://pagus.example');
    }

    public function testWebsiteEntity(): void
    {
        $config = self::config();
        $config->siteName = '파구스';
        $config->siteDescription = '파주 맛집 지도';
        $builder = new JsonLdBuilder($config, 'https://pagus.example');

        self::assertSame([
            '@type' => 'WebSite',
            'name' => '파구스',
            'url' => 'https://pagus.example',
            'description' => '파주 맛집 지도',
        ], $builder->website());
    }

    public function testOrganizationEntity(): void
    {
        $config = self::config();
        $config->siteName = '파구스';
        $builder = new JsonLdBuilder($config, 'https://pagus.example');

        self::assertSame([
            '@type' => 'Organization',
            'name' => '파구스',
            'url' => 'https://pagus.example',
        ], $builder->organization());
    }

    public function testItemListBuildsPositionedEntries(): void
    {
        $builder = self::builder();

        $list = $builder->itemList([
            ['id' => 5, 'name' => '가맛집'],
            ['id' => 9, 'name' => '나맛집'],
        ]);

        self::assertSame('ItemList', $list['@type']);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'url' => 'https://pagus.example/restaurants/5', 'name' => '가맛집'],
            ['@type' => 'ListItem', 'position' => 2, 'url' => 'https://pagus.example/restaurants/9', 'name' => '나맛집'],
        ], $list['itemListElement']);
    }

    public function testRestaurantEntityMapsCoreFields(): void
    {
        $builder = self::builder();

        $entity = $builder->restaurant([
            'id' => 12,
            'name' => '파구스식당',
            'address' => '경기도 파주시 어딘가 1',
            'latitude' => '37.7',
            'longitude' => '126.7',
            'phone' => '031-000-0000',
            'category_names' => '한식, 분식',
        ], []);

        self::assertSame('FoodEstablishment', $entity['@type']);
        self::assertSame('파구스식당', $entity['name']);
        self::assertSame('https://pagus.example/restaurants/12', $entity['url']);
        self::assertSame(['@type' => 'PostalAddress', 'streetAddress' => '경기도 파주시 어딘가 1', 'addressCountry' => 'KR'], $entity['address']);
        self::assertSame(['@type' => 'GeoCoordinates', 'latitude' => 37.7, 'longitude' => 126.7], $entity['geo']);
        self::assertSame('031-000-0000', $entity['telephone']);
        self::assertSame(['한식', '분식'], $entity['servesCuisine']);
        self::assertArrayNotHasKey('aggregateRating', $entity);
    }

    public function testRestaurantOmitsOptionalFieldsWhenAbsent(): void
    {
        $builder = self::builder();

        $entity = $builder->restaurant([
            'id' => 1,
            'name' => '맛집',
            'address' => '파주',
            'latitude' => 37,
            'longitude' => 126,
        ], []);

        self::assertArrayNotHasKey('telephone', $entity);
        self::assertArrayNotHasKey('servesCuisine', $entity);
        self::assertArrayNotHasKey('sameAs', $entity);
    }

    public function testRestaurantIncludesHomepageAsSameAs(): void
    {
        $builder = self::builder();

        $entity = $builder->restaurant([
            'id' => 1,
            'name' => '맛집',
            'address' => '파주',
            'latitude' => 37,
            'longitude' => 126,
            'homepage_url' => 'https://example.com',
        ], []);

        self::assertSame(['https://example.com'], $entity['sameAs']);
    }

    public function testRestaurantComputesAggregateRatingFromVisibleReviews(): void
    {
        $builder = self::builder();

        $entity = $builder->restaurant([
            'id' => 1,
            'name' => '맛집',
            'address' => '파주',
            'latitude' => 37,
            'longitude' => 126,
        ], [
            ['rating' => 5],
            ['rating' => 4],
            ['rating' => 3],
        ]);

        self::assertSame([
            '@type' => 'AggregateRating',
            'ratingValue' => 4.0,
            'reviewCount' => 3,
            'bestRating' => 5,
            'worstRating' => 1,
        ], $entity['aggregateRating']);
    }

    public function testBreadcrumbBuildsPositionedList(): void
    {
        $builder = self::builder();

        $breadcrumb = $builder->breadcrumb([
            ['name' => '홈', 'url' => 'https://pagus.example/'],
            ['name' => '파구스식당', 'url' => 'https://pagus.example/restaurants/12'],
        ]);

        self::assertSame('BreadcrumbList', $breadcrumb['@type']);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => '홈', 'item' => 'https://pagus.example/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '파구스식당', 'item' => 'https://pagus.example/restaurants/12'],
        ], $breadcrumb['itemListElement']);
    }

    public function testGraphWrapsEntitiesWithContext(): void
    {
        $builder = self::builder();

        $graph = $builder->graph([['@type' => 'WebSite', 'name' => 'x']]);

        self::assertSame('https://schema.org', $graph['@context']);
        self::assertSame([['@type' => 'WebSite', 'name' => 'x']], $graph['@graph']);
    }

    public function testToScriptTagEscapesClosingScriptAndUsesAsciiUnicode(): void
    {
        $builder = self::builder();

        $tag = $builder->toScriptTag($builder->graph([['@type' => 'WebSite', 'name' => '</script><b>파구스</b>']]));

        self::assertStringStartsWith('<script type="application/ld+json">', $tag);
        self::assertStringEndsWith('</script>', $tag);
        self::assertStringNotContainsString('</script><b>', $tag);
        self::assertStringContainsString('\\u003C\\/script\\u003E', $tag);
        self::assertStringContainsString('\\ud30c\\uad6c\\uc2a4', $tag);
    }
}
