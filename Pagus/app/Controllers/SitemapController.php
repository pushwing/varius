<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RestaurantManagementService;
use CodeIgniter\HTTP\ResponseInterface;

final class SitemapController extends BaseController
{
    public function index(): ResponseInterface
    {
        $baseUrl = rtrim(base_url(), '/');
        $restaurants = (new RestaurantManagementService())->publishedForSitemap();

        $urls = ['<url><loc>' . $this->escXml($baseUrl . '/') . '</loc></url>'];
        foreach ($restaurants as $restaurant) {
            $loc = $this->escXml($baseUrl . '/restaurants/' . (int) $restaurant['id']);
            $lastmod = $this->lastmod((string) ($restaurant['updated_at'] ?? ''));
            $urls[] = '<url><loc>' . $loc . '</loc>' . $lastmod . '</url>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . implode('', $urls)
            . '</urlset>';

        return $this->response->setContentType('application/xml')->setBody($xml);
    }

    private function lastmod(string $updatedAt): string
    {
        if ($updatedAt === '') {
            return '';
        }
        $timestamp = strtotime($updatedAt);
        if ($timestamp === false) {
            return '';
        }

        return '<lastmod>' . date('Y-m-d', $timestamp) . '</lastmod>';
    }

    private function escXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
