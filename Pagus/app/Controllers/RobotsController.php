<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Seo;

final class RobotsController extends BaseController
{
    /** GEO 노출을 원하는 주요 AI 검색/학습 크롤러 목록 */
    private const AI_CRAWLERS = ['GPTBot', 'OAI-SearchBot', 'PerplexityBot', 'Google-Extended', 'ClaudeBot', 'Bingbot'];

    public function index(): ResponseInterface
    {
        /** @var Seo $config */
        $config = config(Seo::class);
        $baseUrl = rtrim(base_url(), '/');

        $lines = ['User-agent: *', 'Allow: /', 'Disallow: /admin/', 'Disallow: /login', ''];

        if ($config->aiCrawlersAllow) {
            foreach (self::AI_CRAWLERS as $userAgent) {
                $lines[] = 'User-agent: ' . $userAgent;
                $lines[] = 'Allow: /';
            }
            $lines[] = '';
        }

        $lines[] = 'Sitemap: ' . $baseUrl . '/sitemap.xml';

        return $this->response->setContentType('text/plain')->setBody(implode("\n", $lines));
    }
}
