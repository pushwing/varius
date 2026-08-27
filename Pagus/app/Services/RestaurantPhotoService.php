<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RestaurantModel;
use App\Models\RestaurantPhotoModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RestaurantPhotoService
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** @var array<string, string> 허용 MIME 타입 → 저장 확장자 */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @var array<string, string> 허용 클라이언트 확장자 → 정규화된 확장자 */
    private const ALLOWED_EXTENSIONS = [
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
    ];

    public function __construct(
        private readonly ?RestaurantPhotoModel $photos = null,
        private readonly ?RestaurantModel $restaurants = null,
        private readonly string $uploadPath = WRITEPATH . 'uploads/restaurants/',
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function photosForRestaurant(int $restaurantId, bool $includeHidden = false): array
    {
        $model = ($this->photos ?? model(RestaurantPhotoModel::class))->where('restaurant_id', $restaurantId);
        if (! $includeHidden) {
            $model->where('is_hidden', 0);
        }
        return $model->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    /** @return array<string, mixed>|null */
    public function photo(int $photoId): ?array
    {
        $photo = ($this->photos ?? model(RestaurantPhotoModel::class))->find($photoId);
        return is_array($photo) ? $photo : null;
    }

    /** @param array<string, mixed> $photo */
    public function absolutePath(array $photo): string
    {
        return $this->uploadPath . ((int) $photo['restaurant_id']) . '/' . $photo['file_name'];
    }

    /**
     * @param list<UploadedFile> $files
     * @return int 업로드에 성공한 사진 수
     */
    public function uploadPhotos(int $restaurantId, array $files): int
    {
        $restaurantModel = $this->restaurants ?? model(RestaurantModel::class);
        if (! is_array($restaurantModel->find($restaurantId))) {
            throw new InvalidArgumentException('맛집을 찾을 수 없습니다.');
        }

        $directory = $this->uploadPath . $restaurantId;
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('업로드 디렉터리를 생성할 수 없습니다.');
        }

        $photoModel = $this->photos ?? model(RestaurantPhotoModel::class);
        $uploaded = 0;
        foreach ($files as $file) {
            if (! $file->isValid() || $file->hasMoved()) {
                throw new InvalidArgumentException(self::uploadErrorMessage($file->getError()));
            }

            $mimeType = $file->getMimeType();
            $size = $file->getSize();
            $size = $size === false ? 0 : $size;
            $extension = self::assertPhotoUpload($mimeType, $file->getClientExtension(), $size);
            $newName = bin2hex(random_bytes(16)) . '.' . $extension;
            $file->move($directory, $newName);

            try {
                $photoModel->insert([
                    'restaurant_id' => $restaurantId,
                    'file_name' => $newName,
                    'original_name' => $file->getClientName(),
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'is_hidden' => 0,
                    'sort_order' => 0,
                ]);
                $uploaded++;
            } catch (Throwable $exception) {
                @unlink($directory . '/' . $newName);
                throw new RuntimeException('사진 정보를 저장하지 못했습니다.', 0, $exception);
            }
        }

        return $uploaded;
    }

    public function togglePhoto(int $photoId): void
    {
        $model = $this->photos ?? model(RestaurantPhotoModel::class);
        $photo = $model->find($photoId);
        if (! is_array($photo)) {
            throw new InvalidArgumentException('사진을 찾을 수 없습니다.');
        }
        $model->update($photoId, ['is_hidden' => ((int) $photo['is_hidden']) === 1 ? 0 : 1]);
    }

    public function deletePhoto(int $photoId): void
    {
        $model = $this->photos ?? model(RestaurantPhotoModel::class);
        $photo = $model->find($photoId);
        if (! is_array($photo)) {
            throw new InvalidArgumentException('사진을 찾을 수 없습니다.');
        }
        $model->delete($photoId);
        @unlink($this->absolutePath($photo));
    }

    /**
     * PHP 업로드 단계에서 걸러진 파일의 사유를 사용자 메시지로 바꾼다.
     */
    public static function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '파일 크기가 서버 허용 한도를 초과했습니다.',
            UPLOAD_ERR_PARTIAL => '파일이 일부만 전송되었습니다. 다시 시도하세요.',
            UPLOAD_ERR_NO_FILE => '업로드할 파일을 선택하세요.',
            default => '파일을 업로드하지 못했습니다.',
        };
    }

    /**
     * MIME·확장자·크기를 검증하고 저장에 사용할 확장자를 반환한다.
     */
    public static function assertPhotoUpload(string $mimeType, string $clientExtension, int $size): string
    {
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('파일 크기는 5MB 이하만 업로드할 수 있습니다.');
        }
        if (! isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new InvalidArgumentException('허용되지 않은 파일 형식입니다.');
        }
        $normalizedExtension = self::ALLOWED_EXTENSIONS[strtolower($clientExtension)] ?? null;
        if ($normalizedExtension === null || $normalizedExtension !== self::ALLOWED_MIME_TYPES[$mimeType]) {
            throw new InvalidArgumentException('허용되지 않은 확장자입니다.');
        }
        return $normalizedExtension;
    }
}
