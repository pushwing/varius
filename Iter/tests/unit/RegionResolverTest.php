<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\PhotoLocation;
use App\Services\Region\RegionResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 좌표 → 지역 판별 검증 — 실제 번들 GeoJSON 을 사용한다(외부 의존 없음).
 */
final class RegionResolverTest extends CIUnitTestCase
{
    private RegionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RegionResolver(
            FCPATH . 'assets/geo/world-countries.json',
            FCPATH . 'assets/geo/kr-sido.json',
        );
    }

    public function testSeoulResolvesToKoreaSeoul(): void
    {
        $result = $this->resolver->resolve(37.5665, 126.9780); // 서울시청
        $this->assertSame('KR', $result['countryCode']);
        $this->assertSame('KR-11', $result['regionCode']);
    }

    public function testBusanResolvesToKoreaBusan(): void
    {
        $result = $this->resolver->resolve(35.1796, 129.0756); // 부산시청
        $this->assertSame('KR', $result['countryCode']);
        $this->assertSame('KR-26', $result['regionCode']);
    }

    public function testJejuResolvesToKoreaJeju(): void
    {
        $result = $this->resolver->resolve(33.4996, 126.5312); // 제주시
        $this->assertSame('KR', $result['countryCode']);
        $this->assertSame('KR-49', $result['regionCode']);
    }

    public function testTokyoResolvesToJapanWithoutRegion(): void
    {
        $result = $this->resolver->resolve(35.6762, 139.6503); // 도쿄
        $this->assertSame('JP', $result['countryCode']);
        $this->assertNull($result['regionCode']);
    }

    public function testPacificOceanResolvesToNull(): void
    {
        $result = $this->resolver->resolve(0.0, -150.0); // 태평양 한가운데
        $this->assertNull($result['countryCode']);
        $this->assertNull($result['regionCode']);
    }

    public function testEnrichAllSkipsLocationsWithoutCoordinates(): void
    {
        $withGps = new PhotoLocation('a.jpg', 37.5665, 126.9780, '2026-07-20 09:00:00');
        $noGps = new PhotoLocation('b.jpg', null, null, '2026-07-20 10:00:00');

        $enriched = $this->resolver->enrichAll([$withGps, $noGps]);

        $this->assertSame('KR', $enriched[0]->countryCode);
        $this->assertSame('KR-11', $enriched[0]->regionCode);
        $this->assertNull($enriched[1]->countryCode);
        $this->assertNull($enriched[1]->regionCode);
    }
}
