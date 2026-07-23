<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PhotoLocationModel;
use App\Services\FootprintService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 발자국 집계 조립 검증 — DB 는 Mock.
 */
final class FootprintServiceTest extends CIUnitTestCase
{
    public function testBuildForUserAssemblesCountsAndStats(): void
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('countByCountry')->with(7)->willReturn([
            ['code' => 'KR', 'photos' => 310],
            ['code' => 'JP', 'photos' => 42],
        ]);
        $model->method('countByRegion')->with(7)->willReturn([
            ['code' => 'KR-11', 'photos' => 250],
            ['code' => 'KR-49', 'photos' => 60],
        ]);

        $result = (new FootprintService($model))->buildForUser(7);

        $this->assertSame(2, $result['stats']['countryCount']);
        $this->assertSame(2, $result['stats']['regionCount']);
        $this->assertSame('KR', $result['countries'][0]['code']);
        $this->assertSame(60, $result['regions'][1]['photos']);
    }

    public function testBuildForUserWithNoPhotos(): void
    {
        $model = $this->createMock(PhotoLocationModel::class);
        $model->method('countByCountry')->willReturn([]);
        $model->method('countByRegion')->willReturn([]);

        $result = (new FootprintService($model))->buildForUser(7);

        $this->assertSame([], $result['countries']);
        $this->assertSame([], $result['regions']);
        $this->assertSame(0, $result['stats']['countryCount']);
        $this->assertSame(0, $result['stats']['regionCount']);
    }
}
