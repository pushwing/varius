<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Seo;

/**
 * 페이지 <head> SEO 메타(title·description·canonical·robots·OG·Twitter Card)를 렌더링한다.
 * CI4 헬퍼(esc 등)에 의존하지 않는 순수 클래스라 프레임워크 부트스트랩 없이 단위 테스트할 수 있다.
 */
final class SeoHelper
{
    public function __construct(private readonly Seo $config, private readonly string $baseUrl)
    {
    }

    /**
     * @param array{title: string, description: string, path: string, image?: ?string, type?: string, noindex?: bool} $page
     */
    public function head(array $page): string
    {
        $title = (string) $page['title'];
        $description = (string) $page['description'];
        $canonical = $this->absoluteUrl((string) $page['path']);
        $noindex = (bool) ($page['noindex'] ?? false);
        $type = (string) ($page['type'] ?? 'website');
        $image = $page['image'] ?? ($this->config->ogDefaultImage !== '' ? $this->config->ogDefaultImage : null);

        $lines = [
            '<title>' . $this->esc($title) . '</title>',
            '<meta name="description" content="' . $this->esc($description) . '">',
            '<link rel="canonical" href="' . $this->esc($canonical) . '">',
            '<meta name="robots" content="' . ($noindex ? 'noindex, nofollow' : 'index, follow') . '">',
            '<meta property="og:type" content="' . $this->esc($type) . '">',
            '<meta property="og:title" content="' . $this->esc($title) . '">',
            '<meta property="og:description" content="' . $this->esc($description) . '">',
            '<meta property="og:url" content="' . $this->esc($canonical) . '">',
            '<meta property="og:site_name" content="' . $this->esc($this->config->siteName) . '">',
            '<meta property="og:locale" content="ko_KR">',
        ];

        if ($image !== null && $image !== '') {
            $lines[] = '<meta property="og:image" content="' . $this->esc($image) . '">';
            $lines[] = '<meta property="og:image:width" content="1200">';
            $lines[] = '<meta property="og:image:height" content="630">';
        }

        $lines[] = '<meta name="twitter:card" content="' . ($image !== null && $image !== '' ? 'summary_large_image' : 'summary') . '">';
        $lines[] = '<meta name="twitter:title" content="' . $this->esc($title) . '">';
        $lines[] = '<meta name="twitter:description" content="' . $this->esc($description) . '">';
        if ($image !== null && $image !== '') {
            $lines[] = '<meta name="twitter:image" content="' . $this->esc($image) . '">';
        }

        if ($this->config->googleVerify !== '') {
            $lines[] = '<meta name="google-site-verification" content="' . $this->esc($this->config->googleVerify) . '">';
        }
        if ($this->config->bingVerify !== '') {
            $lines[] = '<meta name="msvalidate.01" content="' . $this->esc($this->config->bingVerify) . '">';
        }

        return implode("\n", $lines);
    }

    private function absoluteUrl(string $path): string
    {
        $path = ltrim($path, '/');
        return rtrim($this->baseUrl, '/') . '/' . $path;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
