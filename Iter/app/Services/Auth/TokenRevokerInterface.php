<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * OAuth 토큰 폐기(revoke) 추상화 — 계정 삭제 시 Google 측 오프라인 접근 권한을 회수한다.
 *
 * 실제 HTTP 호출(GoogleTokenRevoker)과 테스트용 대역을 분리하기 위한 경계.
 */
interface TokenRevokerInterface
{
    /**
     * 주어진 토큰(refresh 또는 access)을 발급자에게 폐기 요청한다.
     *
     * 폐기는 best-effort 다 — 네트워크·발급자 오류로 실패하면 예외를 던지며,
     * 호출측(AccountDeletionService)이 로깅 후 로컬 삭제를 계속 진행한다.
     */
    public function revoke(string $token): void;
}
