<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhotoLocationModel;

/**
 * 사진(좌표 레코드) 개별 관리 — 썸네일 회전·사진 삭제.
 *
 * 원본은 저장하지 않으므로 회전은 보관된 썸네일 파일에 적용하고,
 * 삭제는 썸네일 파일과 DB 행을 함께 제거한다. 모든 동작은 소유자 검증을 거친다.
 */
final class PhotoManagementService
{
    public function __construct(
        private readonly PhotoLocationModel $model,
    ) {
    }

    /**
     * 보관된 썸네일을 90도 회전해 저장한다.
     *
     * @param string $direction 'left'(반시계) 또는 'right'(시계)
     *
     * @return bool 소유하지 않았거나 썸네일이 없으면 false
     */
    public function rotateThumbnail(int $id, int $userId, string $direction): bool
    {
        $row = $this->model->findOwned($id, $userId);
        if ($row === null) {
            return false;
        }

        $path = (string) ($row['thumbnail_path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return false;
        }

        $image = @imagecreatefromjpeg($path);
        if ($image === false) {
            return false;
        }

        // GD 의 imagerotate 는 양수 각도가 반시계 방향이다.
        $rotated = imagerotate($image, $direction === 'right' ? -90 : 90, 0);
        if ($rotated === false) {
            return false;
        }

        return imagejpeg($rotated, $path);
    }

    /**
     * 사진을 삭제한다 — 썸네일 파일과 DB 행 모두 제거.
     *
     * @return bool 소유하지 않았으면 false
     */
    public function deletePhoto(int $id, int $userId): bool
    {
        $row = $this->model->findOwned($id, $userId);
        if ($row === null) {
            return false;
        }

        $path = (string) ($row['thumbnail_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }

        $this->model->delete($id);

        return true;
    }
}
