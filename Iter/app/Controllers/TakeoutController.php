<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Google Takeout zip 업로드 컨트롤러.
 *
 * 로직은 UploadedZipHandlerInterface·TakeoutIngestService 에 위임하고,
 * 컨트롤러는 인증 가드·검증·응답만 담당한다.
 */
class TakeoutController extends BaseController
{
    private const MAX_UPLOAD_BYTES = 500 * 1024 * 1024; // 500MB

    /**
     * zip 업로드 — 압축 해제해 동선 좌표를 추출·저장한다(POST /takeout/upload).
     */
    public function upload(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $file = $this->request->getFile('file');
        if ($file === null || strtolower($file->getClientExtension()) !== 'zip') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'zip 파일만 업로드할 수 있습니다.']);
        }

        if ($file->getSize() === null || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일이 너무 큽니다(최대 500MB).']);
        }

        try {
            $zipPath = service('uploadedZipHandler')->store($file, WRITEPATH . 'uploads');
        } catch (Throwable $e) {
            log_message('error', 'Takeout zip 업로드 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(422)->setJSON(['error' => '업로드된 파일을 처리할 수 없습니다.']);
        }

        try {
            $result = service('takeoutIngest')->ingest($zipPath);
        } catch (Throwable $e) {
            log_message('error', 'Takeout 처리 실패: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(502)->setJSON(['error' => 'zip 처리에 실패했습니다.']);
        } finally {
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }

        $saved = model(PhotoLocationModel::class)->saveMany($userId, $result['locations']);

        return $this->response->setJSON([
            'saved' => $saved,
            'totalCandidates' => $result['totalCandidates'],
        ]);
    }
}
