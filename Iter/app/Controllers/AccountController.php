<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Throwable;

/**
 * 계정·데이터 관리 컨트롤러.
 *
 * 로직은 AccountDeletionService 에 위임하고, 컨트롤러는 인증 가드·세션·응답만 담당한다.
 */
class AccountController extends BaseController
{
    /**
     * 내 데이터·계정 전체 삭제(POST /account/delete).
     *
     * 로그인 세션(SameSite=Lax 쿠키)으로만 트리거되는 상태 변경 요청이며,
     * 삭제 완료 후 세션을 초기화하고 랜딩으로 리다이렉트한다.
     */
    public function deleteData(): RedirectResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return redirect()->to('/auth/google');
        }

        try {
            service('accountDeletion')->deleteAllUserData($userId);
        } catch (Throwable $e) {
            log_message('error', '계정 데이터 삭제 실패: user_id={id} msg={msg}', [
                'id' => $userId,
                'msg' => $e->getMessage(),
            ]);

            return redirect()->to('/upload')->with('error', '데이터 삭제에 실패했습니다. 잠시 후 다시 시도해주세요.');
        }

        // 삭제 완료 — 인증 상태를 지우고 세션 ID 를 재발급한다.
        session()->remove('user_id');
        session()->regenerate(true);

        return redirect()->to('/')->with('message', '모든 데이터가 삭제되었습니다.');
    }
}
