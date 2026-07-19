<?php

declare(strict_types=1);

namespace App\Traits;

use CodeIgniter\HTTP\ResponseInterface;

trait ApiResponse
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function success(array $data, array $meta = [], int $statusCode = 200): ResponseInterface
    {
        $body = ['status' => 'success', 'data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return $this->response->setStatusCode($statusCode)->setJSON($body);
    }

    protected function error(string $code, string $message, int $statusCode): ResponseInterface
    {
        return $this->response->setStatusCode($statusCode)->setJSON([
            'status'  => 'error',
            'code'    => $code,
            'message' => $message,
        ]);
    }
}
