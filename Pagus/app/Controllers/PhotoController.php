<?php

namespace App\Controllers;

use App\Services\RestaurantManagementService;
use App\Services\RestaurantPhotoService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

final class PhotoController extends BaseController
{
    private RestaurantPhotoService $photos;
    private RestaurantManagementService $management;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->photos = new RestaurantPhotoService();
        $this->management = new RestaurantManagementService();
    }

    public function show(int $id): ResponseInterface
    {
        $photo = $this->photos->photo($id);
        if ($photo === null || (int) $photo['is_hidden'] === 1 || $this->management->publicRestaurant((int) $photo['restaurant_id']) === null) {
            throw PageNotFoundException::forPageNotFound('사진을 찾을 수 없습니다.');
        }

        return $this->streamPhoto($photo);
    }

    public function adminShow(int $restaurantId, int $id): ResponseInterface
    {
        $photo = $this->photos->photo($id);
        if ($photo === null || (int) $photo['restaurant_id'] !== $restaurantId) {
            throw PageNotFoundException::forPageNotFound('사진을 찾을 수 없습니다.');
        }

        return $this->streamPhoto($photo);
    }

    /** @param array<string, mixed> $photo */
    private function streamPhoto(array $photo): ResponseInterface
    {
        $path = $this->photos->absolutePath($photo);
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw PageNotFoundException::forPageNotFound('사진을 찾을 수 없습니다.');
        }

        return $this->response->setContentType((string) $photo['mime_type'], '')->setBody($contents);
    }
}
