<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * 다운로드한 원본 이미지로부터 지도 미리보기용 썸네일을 생성한다.
 *
 * 풀사이즈 원본은 저장하지 않지만(Iter/CLAUDE.md 저장 정책), 썸네일은 예외로 보관해
 * 재조회 없이 즉시 서빙할 수 있게 한다.
 */
interface ThumbnailGeneratorInterface
{
    /**
     * @param string $sourcePath   원본(임시) 이미지 파일 경로
     * @param string $mediaItemId  파일명 생성에 쓰이는 식별자(사용자당 유니크하지 않을 수 있음 — 예: 카메라 기본 파일명)
     * @param int    $userId       저장 경로를 사용자별로 네임스페이스하기 위한 소유자 식별자
     *
     * @return string|null 생성된 썸네일 경로, 실패(비이미지 등) 시 null
     */
    public function generate(string $sourcePath, string $mediaItemId, int $userId): ?string;
}
