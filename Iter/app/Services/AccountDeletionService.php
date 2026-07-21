<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OAuthTokenModel;
use App\Models\PhotoLocationModel;
use App\Models\UserModel;
use App\Support\Filesystem;
use Config\Database;
use Throwable;

/**
 * 사용자 데이터·계정 전체 삭제 유스케이스.
 *
 * 개인정보처리방침의 "삭제 요청 시 전체 삭제" 약속을 이행한다:
 * ① Google 오프라인 접근 토큰 폐기(best-effort) → ② 좌표·토큰·사용자 레코드 트랜잭션 삭제
 * → ③ 썸네일 디렉터리 삭제. 저장 정책상 원본은 이미 보관하지 않으므로 대상이 아니다.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly PhotoLocationModel $photoLocations,
        private readonly OAuthTokenModel $tokens,
        private readonly UserModel $users,
        private readonly GooglePhotosAuthService $auth,
        private readonly string $thumbnailBaseDir,
    ) {
    }

    /**
     * 사용자의 모든 데이터와 계정을 삭제한다.
     *
     * DB 삭제는 하나의 트랜잭션으로 처리해 부분 삭제를 방지한다. Google 토큰 폐기는
     * 외부 의존이라 실패해도 로컬 삭제를 막지 않는다(로깅 후 진행).
     *
     * @throws \Throwable DB 트랜잭션이 실패하면 롤백 후 예외를 전파한다.
     */
    public function deleteAllUserData(int $userId): void
    {
        // ① Google 측 오프라인 접근 회수(best-effort). 실패해도 로컬 삭제는 계속한다.
        try {
            $this->auth->revokeTokens($userId);
        } catch (Throwable $e) {
            log_message('error', '계정 삭제 중 Google 토큰 폐기 실패: user_id={id} msg={msg}', [
                'id' => $userId,
                'msg' => $e->getMessage(),
            ]);
        }

        // ② 좌표 → 토큰 → 사용자 순으로 트랜잭션 삭제(실패 시 전체 롤백).
        $db = Database::connect();
        $db->transException(true)->transStart();
        $this->photoLocations->where('user_id', $userId)->delete();
        $this->tokens->where('user_id', $userId)->delete();
        $this->users->where('id', $userId)->delete();
        $db->transComplete();

        // ③ DB 커밋 후 썸네일 디렉터리 삭제(파일은 트랜잭션 대상이 아니므로 마지막에).
        Filesystem::removeDirectory($this->userThumbnailDir($userId));
    }

    private function userThumbnailDir(int $userId): string
    {
        return rtrim($this->thumbnailBaseDir, '/') . '/' . $userId;
    }
}
