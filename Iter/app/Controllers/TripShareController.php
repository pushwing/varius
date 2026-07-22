<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Models\TripShareLinkModel;
use App\Support\TimeConverter;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 여행 SNS 공유 — 비로그인 공개 열람.
 *
 * 토큰은 여행 단위로 발급되며(TripController::share), 이 컨트롤러는 토큰이 가리키는
 * 여행의 날짜 범위로 스코프를 좁혀 요약(날짜·사진 그리드)만 읽기 전용으로 노출한다.
 * 시간대별 세부 정보(POI·메모)는 포함하지 않는다.
 */
class TripShareController extends BaseController
{
    /**
     * 공유된 여행 요약 페이지(GET /t/{token}).
     */
    public function show(string $token): ResponseInterface|string
    {
        $tripId = model(TripShareLinkModel::class)->findByToken($token);
        if ($tripId === null) {
            return $this->response->setStatusCode(404);
        }

        $trip = model(TripModel::class)->find($tripId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $userId = (int) $trip['user_id'];
        $summaries = service('tripSummary')->buildDaySummaries($userId, (string) $trip['start_date'], (string) $trip['end_date']);

        $days = [];
        foreach ($summaries as $summary) {
            $thumbnailUrls = [];
            foreach ($summary['thumbnail_ids'] as $photoId) {
                $thumbnailUrls[] = "/t/{$token}/thumbnails/{$photoId}";
            }

            $days[] = [
                'date' => $summary['date'],
                'photo_count' => $summary['photo_count'],
                'thumbnail_urls' => $thumbnailUrls,
            ];
        }

        return view('trip-share', [
            'trip' => [
                'title' => (string) $trip['title'],
                'body' => (string) ($trip['body'] ?? ''),
                'start_date' => (string) $trip['start_date'],
                'end_date' => (string) $trip['end_date'],
            ],
            'days' => $days,
        ]);
    }

    /**
     * 공유된 여행에 속한 썸네일(GET /t/{token}/thumbnails/{id}).
     *
     * 토큰이 가리키는 여행의 날짜 범위·소유자를 벗어난 사진은 존재를 노출하지 않기 위해
     * 404 로 응답한다.
     */
    public function thumbnail(string $token, int $id): ResponseInterface
    {
        $tripId = model(TripShareLinkModel::class)->findByToken($token);
        if ($tripId === null) {
            return $this->response->setStatusCode(404);
        }

        $trip = model(TripModel::class)->find($tripId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $photo = model(PhotoLocationModel::class)->findOwned($id, (int) $trip['user_id']);
        if ($photo === null) {
            return $this->response->setStatusCode(404);
        }

        $takenAtDate = substr(TimeConverter::utcToKst((string) ($photo['taken_at'] ?? '')), 0, 10);
        if ($takenAtDate < (string) $trip['start_date'] || $takenAtDate > (string) $trip['end_date']) {
            return $this->response->setStatusCode(404); // 여행 기간 밖의 사진.
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
