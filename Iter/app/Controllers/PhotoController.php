<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * 사진 개별 관리 — 썸네일 회전·삭제 API.
 *
 * 처리 로직은 PhotoManagementService 에 위임하고, 컨트롤러는 인증 가드·검증·응답만 담당한다.
 */
class PhotoController extends BaseController
{
    /**
     * 썸네일 90도 회전(POST /photos/{id}/rotate, direction=left|right).
     */
    public function rotate(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        $direction = (string) $this->request->getPost('direction');
        if ($direction !== 'left' && $direction !== 'right') {
            return $this->response->setStatusCode(422)->setJSON(['error' => '회전 방향은 left 또는 right 여야 합니다.']);
        }

        if (! service('photoManagement')->rotateThumbnail($id, $userId, $direction)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => '사진을 찾을 수 없습니다.']);
        }

        return $this->response->setJSON(['rotated' => true]);
    }

    /**
     * 사진 삭제 — 썸네일 파일과 좌표 기록 모두 제거(POST /photos/{id}/delete).
     */
    public function delete(int $id): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
        }

        if (! service('photoManagement')->deletePhoto($id, $userId)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => '사진을 찾을 수 없습니다.']);
        }

        return $this->response->setJSON(['deleted' => true]);
    }
}
