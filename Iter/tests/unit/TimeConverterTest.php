<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TimeConverter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TimeConverterTest extends CIUnitTestCase
{
    public function testUtcToKstAddsNineHours(): void
    {
        $this->assertSame('2024-03-15 18:10:00', TimeConverter::utcToKst('2024-03-15 09:10:00'));
    }

    public function testUtcToKstCrossesDateBoundary(): void
    {
        // UTC 저녁은 KST 다음 날 아침 — 날짜 그룹핑이 한국 기준으로 넘어가야 한다.
        $this->assertSame('2024-03-16 08:30:00', TimeConverter::utcToKst('2024-03-15 23:30:00'));
    }

    public function testUtcToKstReturnsInputWhenUnparseable(): void
    {
        $this->assertSame('', TimeConverter::utcToKst(''));
        $this->assertSame('nonsense', TimeConverter::utcToKst('nonsense'));
    }

    public function testKstToUtcSubtractsNineHours(): void
    {
        $this->assertSame('2019-07-18 13:55:29', TimeConverter::kstToUtc('2019-07-18 22:55:29'));
    }

    public function testKstDateToUtcRangeCoversWholeKstDay(): void
    {
        [$start, $end] = TimeConverter::kstDateToUtcRange('2024-03-15');

        // KST 2024-03-15 00:00~23:59:59 는 UTC 로 전날 15:00 ~ 당일 14:59:59.
        $this->assertSame('2024-03-14 15:00:00', $start);
        $this->assertSame('2024-03-15 14:59:59', $end);
    }
}
