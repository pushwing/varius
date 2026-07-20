<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\GoogleApiName;
use App\Services\GoogleApiUsageTracker;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * @internal
 */
final class GoogleApiUsageTrackerTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean(); // 카운터 상태 초기화(테스트 격리)
    }

    private function tracker(): GoogleApiUsageTracker
    {
        return new GoogleApiUsageTracker(Services::cache());
    }

    public function testCountTodayIsZeroBeforeAnyCall(): void
    {
        $this->assertSame(0, $this->tracker()->countToday(GoogleApiName::Picker));
    }

    public function testRecordIncrementsTodayCount(): void
    {
        $tracker = $this->tracker();

        $tracker->record(GoogleApiName::Picker, 200);
        $tracker->record(GoogleApiName::Picker, 200);
        $count = $tracker->record(GoogleApiName::Picker, 429);

        $this->assertSame(3, $count);
        $this->assertSame(3, $tracker->countToday(GoogleApiName::Picker));
    }

    public function testCountsAreIsolatedPerApi(): void
    {
        $tracker = $this->tracker();

        $tracker->record(GoogleApiName::Picker, 200);
        $tracker->record(GoogleApiName::MediaDownload, 200);
        $tracker->record(GoogleApiName::MediaDownload, 200);

        $this->assertSame(1, $tracker->countToday(GoogleApiName::Picker));
        $this->assertSame(2, $tracker->countToday(GoogleApiName::MediaDownload));
    }
}
