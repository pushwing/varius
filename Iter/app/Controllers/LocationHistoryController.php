<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * 위치기록(Timeline.json) — 업로드 인제스트와 날짜별 트랙 API.
 */
final class LocationHistoryController extends BaseController
{
    private const MAX_UPLOAD_BYTES = 64 * 1024 * 1024;

    /**
     * Timeline.json 업로드(POST /location-history/upload).
     */
    public function upload(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $file = $this->request->getFile('file');
        if ($file === null) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Timeline.json 파일을 선택해주세요.']);
        }

        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일이 너무 큽니다. 서버 업로드 용량 제한을 초과했습니다.']);
        }

        if (strtolower($file->getClientExtension()) !== 'json') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'json 파일만 업로드할 수 있습니다.']);
        }

        if ($file->getSize() === null || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일이 너무 큽니다(최대 64MB).']);
        }

        try {
            $jsonPath = service('uploadedZipHandler')->store($file, WRITEPATH . 'uploads');
        } catch (Throwable $e) {
            log_message('error', '위치기록 업로드 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(422)->setJSON(['error' => '업로드된 파일을 처리할 수 없습니다.']);
        }

        try {
            $result = service('locationHistory')->ingestFile($jsonPath, $userId);
        } catch (Throwable $e) {
            log_message('error', '위치기록 처리 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(422)->setJSON(['error' => '위치기록 처리에 실패했습니다. 기기에서 내보낸 Timeline.json 인지 확인해주세요.']);
        } finally {
            if (is_file($jsonPath)) {
                unlink($jsonPath);
            }
        }

        return $this->response->setJSON([
            'saved' => $result['saved'],
            'skipped' => $result['skipped'],
            'total_parsed' => $result['totalParsed'],
            'first_date' => $result['firstDate'],
            'last_date' => $result['lastDate'],
        ]);
    }

    /**
     * 날짜별 트랙 좌표(GET /location-history/track/{date}).
     */
    public function track(string $date): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '날짜 형식이 올바르지 않습니다(YYYY-MM-DD).']);
        }

        return $this->response->setJSON([
            'points' => service('locationHistory')->trackForDate($userId, $date),
        ]);
    }
}
