<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RestaurantManagementService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Seo;

final class LlmsController extends BaseController
{
    public function index(): ResponseInterface
    {
        /** @var Seo $config */
        $config = config(Seo::class);
        $baseUrl = rtrim(base_url(), '/');
        $restaurants = (new RestaurantManagementService())->publishedForSitemap();

        $lines = [
            '# ' . $config->siteName,
            '',
            $config->siteDescription,
            '',
            '홈: ' . $baseUrl . '/',
            '',
            '## 맛집 목록',
        ];
        foreach ($restaurants as $restaurant) {
            $lines[] = '- ' . $this->singleLine((string) $restaurant['name']) . ': ' . $baseUrl . '/restaurants/' . (int) $restaurant['id'];
        }

        return $this->response->setContentType('text/plain')->setBody(implode("\n", $lines));
    }

    private function singleLine(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
