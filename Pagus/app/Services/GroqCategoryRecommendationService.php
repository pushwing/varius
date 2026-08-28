<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\CURLRequest;
use Config\Groq;

final class GroqCategoryRecommendationService
{
    public function __construct(private readonly ?CURLRequest $client = null, private readonly ?Groq $config = null)
    {
    }

    /**
     * 상호와 운영자가 등록한 활성 카테고리를 바탕으로 카테고리 ID를 추천한다.
     * API 실패나 해석할 수 없는 응답은 수동 입력을 보존하기 위해 null을 반환한다.
     *
     * @param list<array<string, mixed>> $categories
     * @return list<int>|null
     */
    public function recommend(string $restaurantName, array $categories): ?array
    {
        $config = $this->config ?? config(Groq::class);
        $restaurantName = trim($restaurantName);
        $available = [];
        foreach ($categories as $category) {
            $id = filter_var($category['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $name = trim((string) ($category['name'] ?? ''));
            if (is_int($id) && $name !== '' && (int) ($category['is_active'] ?? 1) === 1) {
                $available[$id] = $name;
            }
        }
        if ($config->apiKey === '' || $restaurantName === '' || $available === []) {
            return null;
        }

        try {
            $response = ($this->client ?? service('curlrequest', [
                'timeout' => $config->timeout,
                'connect_timeout' => $config->connectTimeout,
                'http_errors' => false,
            ]))->post($config->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'model' => $config->model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => '상호에 가장 적합한 카테고리 ID를 고른다. 반드시 JSON 객체 {"category_ids":[정수]}만 반환한다. 목록 밖의 ID는 반환하지 않는다.'],
                        ['role' => 'user', 'content' => json_encode(['restaurant_name' => $restaurantName, 'categories' => $available], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
                    ],
                ],
                'timeout' => $config->timeout,
                'connect_timeout' => $config->connectTimeout,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }
        $decoded = json_decode($response->getBody(), true);
        $content = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
        if (! is_string($content)) {
            return null;
        }
        $result = json_decode($content, true);
        $ids = is_array($result) ? ($result['category_ids'] ?? null) : null;
        if (! is_array($ids)) {
            return null;
        }

        $recommended = [];
        foreach ($ids as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($id) && isset($available[$id])) {
                $recommended[$id] = $id;
            }
        }
        return array_values($recommended);
    }
}
