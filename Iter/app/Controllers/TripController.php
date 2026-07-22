<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use App\Models\TripModel;
use App\Support\TimeConverter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 여행 그룹핑 — 연속된 날짜를 하나의 여행으로 묶어 커버 사진·기간·사진 수를 보여준다.
 *
 * 여행 경계는 TripSuggestionService 가 자동 제안하고, 사용자가 저장해야 비로소
 * TripModel 레코드가 된다. 멤버십은 별도 테이블 없이 [start_date, end_date] 범위로
 * 계산한다. 컨트롤러는 인증 가드·검증·응답만 담당한다.
 */
class TripController extends BaseController
{
    private const MAX_TITLE_LENGTH = 100;
    private const MAX_BODY_LENGTH = 2000;
    private const MAX_TRIP_DAYS = 60;

    /**
     * "내 여행" 목록 페이지 껍데기(GET /trips).
     */
    public function index(): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('trips', [
            'tripsUrl' => site_url('trips'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }

    /**
     * 저장된 여행 + 자동 제안 목록(JSON, GET /trips/data).
     */
    public function data(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $photoModel = model(PhotoLocationModel::class);
        $summaryService = service('tripSummary');

        $trips = [];
        foreach (model(TripModel::class)->findByUserOrdered($userId) as $trip) {
            $startDate = (string) $trip['start_date'];
            $endDate = (string) $trip['end_date'];
            [$startUtc] = TimeConverter::kstDateToUtcRange($startDate);
            [, $endUtc] = TimeConverter::kstDateToUtcRange($endDate);

            $storedCoverId = $trip['cover_photo_id'] !== null ? (int) $trip['cover_photo_id'] : null;
            $coverId = $summaryService->resolveCoverId($storedCoverId, $userId, $startDate, $endDate);

            $trips[] = [
                'id' => (int) $trip['id'],
                'title' => (string) $trip['title'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'photo_count' => $photoModel->countBetween($userId, $startUtc, $endUtc),
                'cover_thumbnail_url' => $coverId !== null ? '/thumbnails/' . $coverId : null,
            ];
        }

        $suggestions = [];
        foreach (service('tripSuggestion')->suggest($userId) as $s) {
            $suggestions[] = [
                'start_date' => $s['start_date'],
                'end_date' => $s['end_date'],
                'photo_count' => $s['photo_count'],
                'suggested_title' => $s['suggested_title'],
                'first_photo_id' => $s['first_photo_id'],
                'first_thumbnail_url' => $s['first_photo_id'] !== null ? '/thumbnails/' . $s['first_photo_id'] : null,
            ];
        }

        return $this->response->setJSON(['trips' => $trips, 'suggestions' => $suggestions]);
    }

    /**
     * 여행 생성(POST /trips).
     */
    public function create(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $title = trim((string) $this->request->getPost('title'));
        $body = trim((string) $this->request->getPost('body'));
        $startDate = (string) $this->request->getPost('start_date');
        $endDate = (string) $this->request->getPost('end_date');
        $coverRaw = $this->request->getPost('cover_photo_id');

        [$valid, $error] = $this->validateTripFields($title, $body, $startDate, $endDate);
        if (! $valid) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $error]);
        }

        $tripModel = model(TripModel::class);
        if ($tripModel->overlaps($userId, $startDate, $endDate)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '겹치는 여행이 있습니다.']);
        }

        [$coverValid, $coverId, $coverError] = $this->validateCoverPhotoId(
            $coverRaw === null ? null : (string) $coverRaw,
            $userId,
            $startDate,
            $endDate,
        );
        if (! $coverValid) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $coverError]);
        }

        $id = $tripModel->insert([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cover_photo_id' => $coverId,
        ]);

        return $this->response->setJSON([
            'id' => (int) $id,
            'title' => $title,
            'body' => $body,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cover_photo_id' => $coverId,
        ]);
    }

    /**
     * 여행 상세/편집 페이지 껍데기(GET /trips/{id}).
     */
    public function show(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('trip-detail', [
            'tripId' => $id,
            'tripsUrl' => site_url('trips'),
            'uploadUrl' => site_url('upload'),
            'mapUrl' => site_url('map'),
            'logoutUrl' => site_url('auth/logout'),
        ]);
    }

    /**
     * 여행 상세 데이터(JSON, GET /trips/{id}/data).
     */
    public function showData(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $trip = model(TripModel::class)->findOwned($id, $userId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $startDate = (string) $trip['start_date'];
        $endDate = (string) $trip['end_date'];
        $summaryService = service('tripSummary');

        $storedCoverId = $trip['cover_photo_id'] !== null ? (int) $trip['cover_photo_id'] : null;
        $coverId = $summaryService->resolveCoverId($storedCoverId, $userId, $startDate, $endDate);

        $days = [];
        foreach ($summaryService->buildDaySummaries($userId, $startDate, $endDate) as $summary) {
            $firstId = $summary['thumbnail_ids'][0] ?? null;
            $days[] = [
                'date' => $summary['date'],
                'photo_count' => $summary['photo_count'],
                'first_photo_id' => $firstId,
                'first_thumbnail_url' => $firstId !== null ? '/thumbnails/' . $firstId : null,
            ];
        }

        return $this->response->setJSON([
            'trip' => [
                'id' => (int) $trip['id'],
                'title' => (string) $trip['title'],
                'body' => (string) ($trip['body'] ?? ''),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'cover_photo_id' => $storedCoverId,
                'cover_thumbnail_url' => $coverId !== null ? '/thumbnails/' . $coverId : null,
            ],
            'days' => $days,
        ]);
    }

    /**
     * 여행 수정(POST /trips/{id}/update).
     */
    public function update(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $tripModel = model(TripModel::class);
        $trip = $tripModel->findOwned($id, $userId);
        if ($trip === null) {
            return $this->response->setStatusCode(404);
        }

        $title = trim((string) $this->request->getPost('title'));
        $body = trim((string) $this->request->getPost('body'));
        $startDate = (string) $this->request->getPost('start_date');
        $endDate = (string) $this->request->getPost('end_date');
        $coverRaw = $this->request->getPost('cover_photo_id');

        [$valid, $error] = $this->validateTripFields($title, $body, $startDate, $endDate);
        if (! $valid) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $error]);
        }

        if ($tripModel->overlaps($userId, $startDate, $endDate, $id)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '겹치는 여행이 있습니다.']);
        }

        [$coverValid, $coverId, $coverError] = $this->validateCoverPhotoId(
            $coverRaw === null ? null : (string) $coverRaw,
            $userId,
            $startDate,
            $endDate,
        );
        if (! $coverValid) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $coverError]);
        }

        $tripModel->update($id, [
            'title' => $title,
            'body' => $body,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cover_photo_id' => $coverId,
        ]);

        return $this->response->setJSON([
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cover_photo_id' => $coverId,
        ]);
    }

    /**
     * 여행 삭제 — Trip 레코드만 지운다(사진·노트는 그대로 남는다, POST /trips/{id}/delete).
     */
    public function delete(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $tripModel = model(TripModel::class);
        if ($tripModel->findOwned($id, $userId) === null) {
            return $this->response->setStatusCode(404);
        }

        $tripModel->delete($id);

        return $this->response->setJSON(['deleted' => true]);
    }

    /**
     * 제목·설명·기간 유효성을 검증한다.
     *
     * @return array{0: bool, 1: string} [유효 여부, 에러 메시지]
     */
    private function validateTripFields(string $title, string $body, string $startDate, string $endDate): array
    {
        if (! $this->isValidDate($startDate) || ! $this->isValidDate($endDate)) {
            return [false, '날짜 형식이 올바르지 않습니다(YYYY-MM-DD).'];
        }
        if ($endDate < $startDate) {
            return [false, '종료일은 시작일 이후여야 합니다.'];
        }
        if ((strtotime($endDate) - strtotime($startDate)) / 86400 + 1 > self::MAX_TRIP_DAYS) {
            return [false, '여행 기간은 최대 ' . self::MAX_TRIP_DAYS . '일까지 설정할 수 있습니다.'];
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return [false, '제목은 ' . self::MAX_TITLE_LENGTH . '자 이하여야 합니다.'];
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return [false, '설명은 ' . self::MAX_BODY_LENGTH . '자 이하여야 합니다.'];
        }

        return [true, ''];
    }

    /**
     * cover_photo_id 원시 입력을 검증한다.
     *
     * @return array{0: bool, 1: int|null, 2: string} [유효 여부, 사진 id, 에러 메시지]
     */
    private function validateCoverPhotoId(?string $raw, int $userId, string $startDate, string $endDate): array
    {
        if ($raw === null || $raw === '') {
            return [true, null, ''];
        }
        if (! ctype_digit($raw)) {
            return [false, null, '커버 사진 지정이 올바르지 않습니다.'];
        }

        $id = (int) $raw;
        $photo = model(PhotoLocationModel::class)->findOwned($id, $userId);
        if ($photo === null) {
            return [false, null, '커버 사진을 찾을 수 없습니다.'];
        }

        $date = substr(TimeConverter::utcToKst((string) ($photo['taken_at'] ?? '')), 0, 10);
        if ($date < $startDate || $date > $endDate) {
            return [false, null, '커버 사진은 여행 기간 안의 사진이어야 합니다.'];
        }

        return [true, $id, ''];
    }

    /**
     * YYYY-MM-DD 형식이면서 실제 존재하는 날짜인지 검증한다.
     */
    private function isValidDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
