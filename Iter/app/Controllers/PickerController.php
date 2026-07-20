<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Google Photos Picker 세션 흐름 컨트롤러.
 *
 * 로직은 PhotoPickerService 에 위임하고, 컨트롤러는 인증 가드·세션·응답만 담당한다.
 * 액세스 토큰은 GooglePhotosAuthService 로 획득해 서비스에 주입하며 응답·로그에 노출하지 않는다.
 */
class PickerController extends BaseController
{
    /**
     * 세션 시작 — Picker 세션을 생성하고 pickerUri 를 반환한다(POST /picker/sessions).
     * 발급된 sessionId 는 이후 상태 조회·목록 조회를 위해 PHP 세션에 보관한다.
     */
    public function create(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $token = service('googlePhotosAuth')->getValidAccessToken($userId);
            $session = service('photoPicker')->createSession($token);
        } catch (Throwable $e) {
            return $this->serviceFailure($e);
        }

        $sessionId = isset($session['id']) ? (string) $session['id'] : '';
        session()->set('picker_session_id', $sessionId);

        return $this->response->setStatusCode(201)->setJSON([
            'sessionId' => $sessionId,
            'pickerUri' => $session['pickerUri'] ?? null,
        ]);
    }

    /**
     * 상태 확인 — 저장된 세션을 단건 폴링해 사용자 선택 완료 여부를 반환한다
     * (GET /picker/sessions/status). 블로킹 대기는 하지 않는다.
     */
    public function status(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $sessionId = $this->activeSessionId();
        if ($sessionId === null) {
            return $this->noActiveSession();
        }

        try {
            $token = service('googlePhotosAuth')->getValidAccessToken($userId);
            $session = service('photoPicker')->getSession($token, $sessionId);
        } catch (Throwable $e) {
            return $this->serviceFailure($e);
        }

        return $this->response->setJSON([
            'mediaItemsSet' => ($session['mediaItemsSet'] ?? false) === true,
        ]);
    }

    /**
     * 선택 항목 조회 — 준비 완료 시 선택된 미디어 항목(최대 10장)을 반환한다
     * (GET /picker/media-items). 아직 준비되지 않았으면 409 로 응답한다.
     */
    public function items(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $sessionId = $this->activeSessionId();
        if ($sessionId === null) {
            return $this->noActiveSession();
        }

        try {
            $token = service('googlePhotosAuth')->getValidAccessToken($userId);
            $session = service('photoPicker')->getSession($token, $sessionId);

            if (($session['mediaItemsSet'] ?? false) !== true) {
                return $this->response->setStatusCode(409)->setJSON([
                    'error' => '아직 사진 선택이 완료되지 않았습니다.',
                ]);
            }

            $items = service('photoPicker')->listPickedMediaItems($token, $sessionId);
        } catch (Throwable $e) {
            return $this->serviceFailure($e);
        }

        return $this->response->setJSON(['mediaItems' => $items]);
    }

    /**
     * 로그인 사용자 id 를 세션에서 얻는다. 없으면 null.
     */
    private function currentUserId(): ?int
    {
        $userId = session()->get('user_id');

        return is_int($userId) && $userId > 0 ? $userId : null;
    }

    /**
     * 진행 중인 Picker 세션 id 를 얻는다. 없으면 null.
     */
    private function activeSessionId(): ?string
    {
        $sessionId = session()->get('picker_session_id');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->response->setStatusCode(401)->setJSON(['error' => '로그인이 필요합니다.']);
    }

    private function noActiveSession(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setJSON(['error' => '진행 중인 Picker 세션이 없습니다.']);
    }

    /**
     * 서비스(외부 API) 실패를 공통 처리한다. 토큰 등 민감정보는 로그·응답에 남기지 않는다.
     */
    private function serviceFailure(Throwable $e): ResponseInterface
    {
        log_message('error', 'Picker 처리 실패: {msg}', ['msg' => $e->getMessage()]);

        return $this->response->setStatusCode(502)->setJSON(['error' => 'Picker 요청 처리에 실패했습니다.']);
    }
}
