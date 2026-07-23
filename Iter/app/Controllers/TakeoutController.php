<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PhotoLocationModel;
use App\Services\Ingest\PhotoLocation;
use App\Support\TimeConverter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * 사진 zip 업로드 컨트롤러.
 *
 * Google Takeout zip(사이드카 JSON)과 일반 압축파일(사진 EXIF) 두 경로를 처리한다.
 * 실제 로직은 UploadedZipHandlerInterface·인제스트 서비스에 위임하고,
 * 컨트롤러는 인증 가드·검증·응답만 담당한다.
 */
class TakeoutController extends BaseController
{
    private const MAX_UPLOAD_BYTES = 500 * 1024 * 1024; // 500MB

    /**
     * 인제스트 처리(최대 200장 GD 썸네일 디코딩·리샘플링 포함) 허용 시간(초).
     *
     * 사진 수만큼 순차 누적되는 CPU 바운드 작업이라 PHP 기본 max_execution_time(보통 30초)을
     * 쉽게 넘긴다 — 장당 넉넉히 6초를 잡아 상한(200장)까지 안전하게 처리한다.
     */
    private const MAX_PROCESSING_SECONDS = 1200;

    /**
     * 사진 가져오기(업로드) 화면 — 로그인 사용자 전용(GET /upload).
     */
    public function form(): ResponseInterface|RedirectResponse|string
    {
        if ($this->currentUserId() === null) {
            return redirect()->to('/auth/google');
        }

        helper('url');

        return view('upload', [
            'loginUrl' => site_url('auth/google'),
            'logoutUrl' => site_url('auth/logout'),
            'mapUrl' => site_url('map'),
            'tripsUrl' => site_url('trips'),
            'uploadUrl' => site_url('takeout/upload'),
            'plainUploadUrl' => site_url('photos/upload'),
            'locationHistoryUploadUrl' => site_url('location-history/upload'),
            'deleteUrl' => site_url('account/delete'),
        ]);
    }

    /**
     * Google Takeout zip 업로드 — JSON 사이드카에서 좌표 추출·저장(POST /takeout/upload).
     */
    public function upload(): ResponseInterface
    {
        return $this->handleZipUpload('takeoutIngest', 'Takeout');
    }

    /**
     * 일반 압축파일 업로드 — 사진 EXIF 에서 좌표 추출·저장(POST /photos/upload).
     */
    public function uploadPlain(): ResponseInterface
    {
        return $this->handleZipUpload('plainZipIngest', '일반 압축');
    }

    /**
     * zip 업로드 공통 처리: 인증·검증 → 저장 → 인제스트 → 좌표 저장.
     *
     * 좌표를 어느 인제스트 서비스로 뽑느냐만 다르다(Takeout / 일반 압축).
     */
    private function handleZipUpload(string $ingestServiceName, string $context): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $file = $this->request->getFile('file');
        if ($file === null) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'zip 파일을 선택해주세요.']);
        }

        // upload_max_filesize/post_max_size 초과 시 PHP 가 tmp_name 없이 이 에러 코드로
        // $_FILES 를 채운다 — 확장자 검사보다 먼저 걸러야 "zip 파일만..." 오해를 막는다.
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일이 너무 큽니다. 서버 업로드 용량 제한을 초과했습니다.']);
        }

        if (strtolower($file->getClientExtension()) !== 'zip') {
            log_message('info', '{ctx} 업로드 확장자 거부: name={name} ext={ext} error={code}({errorString}) size={size} mime={mime}', [
                'ctx' => $context,
                'name' => $file->getClientName(),
                'ext' => $file->getClientExtension(),
                'code' => $file->getError(),
                'errorString' => $file->getErrorString(),
                'size' => $file->getSize() ?? 'null',
                'mime' => $file->getClientMimeType(),
            ]);

            return $this->response->setStatusCode(422)->setJSON(['error' => 'zip 파일만 업로드할 수 있습니다.']);
        }

        if ($file->getSize() === null || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->response->setStatusCode(413)->setJSON(['error' => '파일이 너무 큽니다(최대 500MB).']);
        }

        try {
            $zipPath = service('uploadedZipHandler')->store($file, WRITEPATH . 'uploads');
        } catch (Throwable $e) {
            log_message('error', '{ctx} zip 업로드 실패: {msg}', ['ctx' => $context, 'msg' => $e->getMessage()]);

            return $this->response->setStatusCode(422)->setJSON(['error' => '업로드된 파일을 처리할 수 없습니다.']);
        }

        // 200장 상한까지 GD 썸네일 생성이 누적되면 기본 실행시간 제한을 넘길 수 있어
        // 이 요청에 한해 넉넉히 늘린다(무한루프가 아니라 정상적인 CPU 바운드 작업).
        set_time_limit(self::MAX_PROCESSING_SECONDS);

        try {
            $result = service($ingestServiceName)->ingest($zipPath, $userId);
        } catch (Throwable $e) {
            log_message('error', '{ctx} 처리 실패: {msg}', ['ctx' => $context, 'msg' => $e->getMessage()]);

            return $this->response->setStatusCode(502)->setJSON(['error' => 'zip 처리에 실패했습니다.']);
        } finally {
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }

        try {
            // 발자국 지도용 지역 코드를 입힌 뒤 저장한다(좌표 없는 사진은 그대로 통과).
            $enriched = service('regionResolver')->enrichAll($result['locations']);
            $saved = model(PhotoLocationModel::class)->saveMany($userId, $enriched);
        } catch (Throwable $e) {
            log_message('error', '{ctx} 좌표 저장 실패: {msg}', ['ctx' => $context, 'msg' => $e->getMessage()]);
            // 저장이 실패하면 방금 만든 썸네일은 DB 참조가 없는 고아가 되므로 즉시 정리한다.
            $this->deleteThumbnails($result['locations']);

            return $this->response->setStatusCode(500)->setJSON(['error' => '좌표 저장에 실패했습니다.']);
        }

        return $this->response->setJSON([
            'saved' => $saved,
            'totalCandidates' => $result['totalCandidates'],
            'capped' => $result['capped'],
            // 업로드 완료 후 "지도에서 보기"가 이 날짜로 바로 이동하기 위한 값(KST, YYYY-MM-DD).
            'latestDate' => $this->latestKstDate($result['locations']),
        ]);
    }

    /**
     * 업로드된 좌표들 중 가장 늦은 촬영 시각의 KST 날짜를 반환한다(없으면 null).
     *
     * @param list<PhotoLocation> $locations
     */
    private function latestKstDate(array $locations): ?string
    {
        $latestUtc = null;
        foreach ($locations as $location) {
            if ($latestUtc === null || $location->takenAt > $latestUtc) {
                $latestUtc = $location->takenAt;
            }
        }

        return $latestUtc === null ? null : substr(TimeConverter::utcToKst($latestUtc), 0, 10);
    }

    /**
     * 좌표에 딸린 썸네일 파일을 삭제한다(저장 실패 롤백용, best-effort).
     *
     * @param list<\App\Services\Ingest\PhotoLocation> $locations
     */
    private function deleteThumbnails(array $locations): void
    {
        foreach ($locations as $location) {
            if ($location->thumbnailPath !== null && is_file($location->thumbnailPath)) {
                unlink($location->thumbnailPath);
            }
        }
    }
}
