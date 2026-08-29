<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class Seo extends BaseConfig
{
    public string $siteName = '파구스 · 파주 로컬 맛집 지도';

    public string $siteDescription = '마을(Pagus) 속 진짜 맛집을 찾다, 파주 로컬 맛집 지도 파구스.';

    /**
     * 페이지에 og:image가 없을 때 쓸 절대 URL. 비워두면 og:image 태그 자체를 생략한다.
     */
    public string $ogDefaultImage = '';

    /**
     * Google Search Console 소유 확인 메타 값. 비워두면 태그를 생략한다.
     */
    public string $googleVerify = '';

    /**
     * Bing Webmaster Tools 소유 확인 메타 값. 비워두면 태그를 생략한다.
     */
    public string $bingVerify = '';

    /**
     * AI 검색/학습 크롤러(GPTBot 등) 허용 여부. robots.txt에 반영된다.
     */
    public bool $aiCrawlersAllow = true;
}
