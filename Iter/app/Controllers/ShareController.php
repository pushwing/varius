<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use App\Models\ShareLinkModel;
use App\Support\TimeConverter;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 시간표(여행 스케줄) SNS 공유 — 비로그인 공개 열람.
 *
 * 토큰은 (사용자, 날짜) 단위로 발급되며(TimelineController::share), 이 컨트롤러는
 * 토큰 소유자·날짜로 스코프를 좁혀 그 날의 스케줄만 읽기 전용으로 노출한다.
 * 주변 업장(POI)은 공개 페이지에서 생략한다(외부 API 남용 방지).
 */
class ShareController extends BaseController
{
    /**
     * 공유된 시간표 페이지(GET /s/{token}).
     */
    public function show(string $token): ResponseInterface|string
    {
        $share = model(ShareLinkModel::class)->findByToken($token);
        if ($share === null) {
            return $this->response->setStatusCode(404);
        }

        $timeline = service('timeline')->buildForDate($share['user_id'], $share['share_date']);

        // 썸네일 URL을 공개(비로그인) 엔드포인트로 바꿔치기한다.
        foreach ($timeline['slots'] as &$slot) {
            foreach ($slot['photos'] as &$photo) {
                if ($photo['thumbnail_url'] !== null) {
                    $photo['thumbnail_url'] = str_replace('/thumbnails/', "/s/{$token}/thumbnails/", $photo['thumbnail_url']);
                }
            }
        }
        unset($slot, $photo);

        return view('share', [
            'date' => $timeline['date'],
            'dayNote' => $timeline['day_note'],
            'slots' => $timeline['slots'],
        ]);
    }

    /**
     * 공유된 시간표에 속한 썸네일(GET /s/{token}/thumbnails/{id}).
     *
     * 토큰의 소유자·날짜 범위를 벗어난 사진은 존재를 노출하지 않기 위해 404 로 응답한다.
     */
    public function thumbnail(string $token, int $id): ResponseInterface
    {
        $share = model(ShareLinkModel::class)->findByToken($token);
        if ($share === null) {
            return $this->response->setStatusCode(404);
        }

        $photo = model(PhotoLocationModel::class)->findOwned($id, $share['user_id']);
        if ($photo === null) {
            return $this->response->setStatusCode(404);
        }

        $takenAtKst = TimeConverter::utcToKst((string) ($photo['taken_at'] ?? ''));
        if (substr($takenAtKst, 0, 10) !== $share['share_date']) {
            return $this->response->setStatusCode(404); // 공유된 날짜 밖의 사진.
        }

        $path = (string) ($photo['thumbnail_path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return $this->response->setStatusCode(404);
        }

        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setContentType('image/jpeg')
            ->setBody((string) file_get_contents($path));
    }
}
