<?php

declare(strict_types=1);

namespace App\Libraries\Seo;

use Config\Seo;

/**
 * schema.org JSON-LD 엔티티를 조립한다(GEO용 구조화 데이터).
 * CI4 헬퍼에 의존하지 않는 순수 클래스라 프레임워크 부트스트랩 없이 단위 테스트할 수 있다.
 */
final class JsonLdBuilder
{
    public function __construct(private readonly Seo $config, private readonly string $baseUrl)
    {
    }

    /** @return array<string, mixed> */
    public function website(): array
    {
        return [
            '@type' => 'WebSite',
            'name' => $this->config->siteName,
            'url' => $this->baseUrl,
            'description' => $this->config->siteDescription,
        ];
    }

    /** @return array<string, mixed> */
    public function organization(): array
    {
        return [
            '@type' => 'Organization',
            'name' => $this->config->siteName,
            'url' => $this->baseUrl,
        ];
    }

    /**
     * @param list<array<string, mixed>> $restaurants id·name을 가진 맛집 목록(공개 목록 페이지)
     * @return array<string, mixed>
     */
    public function itemList(array $restaurants): array
    {
        $elements = [];
        foreach ($restaurants as $index => $restaurant) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => $this->restaurantUrl((int) $restaurant['id']),
                'name' => (string) $restaurant['name'],
            ];
        }

        return ['@type' => 'ItemList', 'itemListElement' => $elements];
    }

    /**
     * @param array<string, mixed> $restaurant 맛집 상세 데이터(publicRestaurant() 결과)
     * @param list<array<string, mixed>> $reviews 공개된(is_hidden=0) 후기 목록
     * @return array<string, mixed>
     */
    public function restaurant(array $restaurant, array $reviews): array
    {
        $entity = [
            '@type' => 'FoodEstablishment',
            'name' => (string) $restaurant['name'],
            'url' => $this->restaurantUrl((int) $restaurant['id']),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => (string) $restaurant['address'],
                'addressCountry' => 'KR',
            ],
            'geo' => [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $restaurant['latitude'],
                'longitude' => (float) $restaurant['longitude'],
            ],
        ];

        $phone = trim((string) ($restaurant['phone'] ?? ''));
        if ($phone !== '') {
            $entity['telephone'] = $phone;
        }

        $homepageUrl = trim((string) ($restaurant['homepage_url'] ?? ''));
        if ($homepageUrl !== '') {
            $entity['sameAs'] = [$homepageUrl];
        }

        $categoryNames = trim((string) ($restaurant['category_names'] ?? ''));
        if ($categoryNames !== '') {
            $entity['servesCuisine'] = array_map('trim', explode(',', $categoryNames));
        }

        $ratings = array_map(static fn (array $review): int => (int) $review['rating'], $reviews);
        if ($ratings !== []) {
            $entity['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round(array_sum($ratings) / count($ratings), 1),
                'reviewCount' => count($ratings),
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        return $entity;
    }

    /**
     * @param list<array{name: string, url: string}> $items
     * @return array<string, mixed>
     */
    public function breadcrumb(array $items): array
    {
        $elements = [];
        foreach ($items as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    public function graph(array $entities): array
    {
        return ['@context' => 'https://schema.org', '@graph' => $entities];
    }

    /**
     * @param array<string, mixed> $graph
     */
    public function toScriptTag(array $graph): string
    {
        $json = json_encode($graph, JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    private function restaurantUrl(int $id): string
    {
        return rtrim($this->baseUrl, '/') . '/restaurants/' . $id;
    }
}
