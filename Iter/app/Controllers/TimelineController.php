<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DayNoteModel;
use App\Models\ShareLinkModel;
use App\Models\TimeNoteModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * 날짜별 시간 동선(여행 스케줄) — 시간대별 사진·노트 JSON API.
 *
 * 조합 로직은 TimelineService, 주변 업장 조회는 PoiLookup 서비스에 위임하고,
 * 컨트롤러는 인증 가드·입력 검증·응답만 담당한다.
 */
class TimelineController extends BaseController
{
    private const MAX_TITLE_LENGTH = 100;
    private const MAX_BODY_LENGTH = 2000;
    private const MAX_MEMO_LENGTH = 500;

    /**
     * 특정 날짜의 시간별 동선(JSON) — 시간대별 사진 + 날짜 노트 + 시간대 메모(GET /timeline/{date}).
     */
    public function data(string $date = ''): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        if (! $this->isValidDate($date)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '날짜 형식이 올바르지 않습니다(YYYY-MM-DD).']);
        }

        return $this->response->setJSON(service('timeline')->buildForDate($userId, $date));
    }

    /**
     * 날짜 노트(제목·내용) 저장 — 제목·내용이 모두 비면 삭제(POST /timeline/day-note).
     */
    public function saveDayNote(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $date = (string) $this->request->getPost('date');
        $title = trim((string) $this->request->getPost('title'));
        $body = trim((string) $this->request->getPost('body'));

        if (! $this->isValidDate($date)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '날짜 형식이 올바르지 않습니다(YYYY-MM-DD).']);
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '제목은 ' . self::MAX_TITLE_LENGTH . '자 이하여야 합니다.']);
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '내용은 ' . self::MAX_BODY_LENGTH . '자 이하여야 합니다.']);
        }

        model(DayNoteModel::class)->upsertNote($userId, $date, $title, $body);

        return $this->response->setJSON(['saved' => true, 'title' => $title, 'body' => $body]);
    }

    /**
     * 세그먼트 메모 저장 — 메모가 비면 삭제(POST /timeline/time-note).
     */
    public function saveTimeNote(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $date = (string) $this->request->getPost('date');
        $slot = (string) $this->request->getPost('slot');
        $memo = trim((string) $this->request->getPost('memo'));

        if (! $this->isValidDate($date)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '날짜 형식이 올바르지 않습니다(YYYY-MM-DD).']);
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $slot) !== 1) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '시각 형식이 올바르지 않습니다(HH:MM).']);
        }
        if (mb_strlen($memo) > self::MAX_MEMO_LENGTH) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '메모는 ' . self::MAX_MEMO_LENGTH . '자 이하여야 합니다.']);
        }

        model(TimeNoteModel::class)->upsertNote($userId, $date, $slot, $memo);

        return $this->response->setJSON(['saved' => true, 'memo' => $memo]);
    }

    /**
     * 시간표 SNS 공유 링크 생성(POST /timeline/share). 같은 날짜를 다시 공유해도
     * 기존 링크를 재사용한다(이미 퍼진 링크가 깨지지 않도록).
     */
    public function share(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $date = (string) $this->request->getPost('date');
        if (! $this->isValidDate($date)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '날짜 형식이 올바르지 않습니다(YYYY-MM-DD).']);
        }

        $token = model(ShareLinkModel::class)->createOrGet($userId, $date);

        helper('url');

        return $this->response->setJSON(['url' => site_url('s/' . $token)]);
    }

    /**
     * 좌표 주변 업장 목록(JSON) — 식당·카페 등(GET /timeline/poi?lat=&lng=).
     */
    public function poi(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $lat = $this->request->getGet('lat');
        $lng = $this->request->getGet('lng');

        if (! is_numeric($lat) || ! is_numeric($lng)
            || (float) $lat < -90 || (float) $lat > 90
            || (float) $lng < -180 || (float) $lng > 180) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '좌표가 올바르지 않습니다.']);
        }

        try {
            $places = service('poiLookup')->findNearby((float) $lat, (float) $lng);
        } catch (Throwable $e) {
            log_message('error', '주변 업장 조회 실패: {message}', ['message' => $e->getMessage()]);

            return $this->response->setStatusCode(502)->setJSON(['error' => '주변 업장 정보를 가져오지 못했습니다.']);
        }

        return $this->response->setJSON(['places' => $places]);
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
