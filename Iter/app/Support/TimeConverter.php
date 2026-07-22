<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 촬영 시각 타임존 변환 유틸.
 *
 * 저장은 UTC("Y-m-d H:i:s") 를 표준으로 하고, 화면 표시·날짜/시간 그룹핑은
 * 한국시간(KST) 으로 변환해 수행한다. 서버 타임존 설정과 무관하게 동작한다.
 */
final class TimeConverter
{
    private const FORMAT = 'Y-m-d H:i:s';
    private const UTC = 'UTC';
    private const KST = 'Asia/Seoul';

    /**
     * UTC 시각 문자열을 KST 문자열로 변환한다. 파싱 불가하면 입력을 그대로 돌려준다.
     */
    public static function utcToKst(string $utc): string
    {
        return self::convert($utc, self::UTC, self::KST);
    }

    /**
     * KST 시각 문자열을 UTC 문자열로 변환한다(EXIF 등 로컬 시각의 저장용).
     */
    public static function kstToUtc(string $kst): string
    {
        return self::convert($kst, self::KST, self::UTC);
    }

    /**
     * KST 기준 하루(YYYY-MM-DD)를 커버하는 UTC 시각 범위를 반환한다.
     *
     * @return array{string, string} [시작(포함), 끝(포함)]
     */
    public static function kstDateToUtcRange(string $kstDate): array
    {
        $start = new DateTimeImmutable($kstDate . ' 00:00:00', new DateTimeZone(self::KST));
        $end = new DateTimeImmutable($kstDate . ' 23:59:59', new DateTimeZone(self::KST));
        $utc = new DateTimeZone(self::UTC);

        return [
            $start->setTimezone($utc)->format(self::FORMAT),
            $end->setTimezone($utc)->format(self::FORMAT),
        ];
    }

    private static function convert(string $time, string $fromTz, string $toTz): string
    {
        $parsed = DateTimeImmutable::createFromFormat(self::FORMAT, $time, new DateTimeZone($fromTz));
        if ($parsed === false) {
            return $time;
        }

        return $parsed->setTimezone(new DateTimeZone($toTz))->format(self::FORMAT);
    }
}
