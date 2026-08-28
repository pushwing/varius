<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GroqCategoryRecommendationService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Groq;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GroqCategoryRecommendationServiceTest extends TestCase
{
    private static function config(): Groq
    {
        /** @var Groq */
        return (new ReflectionClass(Groq::class))->newInstanceWithoutConstructor();
    }

    /** @return list<array<string, mixed>> */
    private static function categories(): array
    {
        return [
            ['id' => 1, 'name' => '한식', 'is_active' => 1],
            ['id' => 2, 'name' => '카페', 'is_active' => 1],
            ['id' => 3, 'name' => '숨김', 'is_active' => 0],
        ];
    }

    public function testReturnsNullWhenApiKeyIsNotConfigured(): void
    {
        $config = self::config();
        $service = new GroqCategoryRecommendationService(null, $config);

        self::assertNull($service->recommend('파구스식당', self::categories()));
    }

    public function testReturnsOnlyRecommendedIdsFromAvailableActiveCategories(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode([
            'choices' => [['message' => ['content' => '{"category_ids":[2,3,999,2]}']]],
        ], JSON_THROW_ON_ERROR));
        $client = $this->createMock(CURLRequest::class);
        $client->expects(self::once())->method('post')->with(
            $config->endpoint,
            self::callback(static function (array $options): bool {
                return ($options['headers']['Authorization'] ?? null) === 'Bearer test-api-key'
                    && ($options['json']['model'] ?? null) === 'llama-3.1-8b-instant'
                    && ($options['json']['messages'][1]['content'] ?? '') !== '';
            }),
        )->willReturn($response);

        $service = new GroqCategoryRecommendationService($client, $config);

        self::assertSame([2], $service->recommend('파구스식당', self::categories()));
    }

    public function testReturnsNullWhenProviderFails(): void
    {
        $config = self::config();
        $config->apiKey = 'test-api-key';
        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willThrowException(new \RuntimeException('timeout'));

        $service = new GroqCategoryRecommendationService($client, $config);

        self::assertNull($service->recommend('파구스식당', self::categories()));
    }
}
