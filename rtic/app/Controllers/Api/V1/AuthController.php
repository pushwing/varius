<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Exceptions\InvalidCredentialsException;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

final class AuthController extends BaseApiController
{
    public function create(): ResponseInterface
    {
        $body = $this->jsonBody();

        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required|min_length[8]',
        ];

        if (!$this->validateData($body, $rules)) {
            $errors = $this->validator->getErrors();

            return $this->error('VALIDATION_ERROR', (string) reset($errors), 422);
        }

        try {
            $userId = $this->authenticate((string) $body['email'], (string) $body['password']);
        } catch (InvalidCredentialsException $e) {
            return $this->error($e->errorCode(), $e->getMessage(), $e->httpStatusCode());
        }

        $issued = Services::liveKitAccessTokenService()->issue((string) $userId);

        return $this->success([
            'access_token' => $issued['token'],
            'livekit_url'  => $issued['url'],
            'room'         => $issued['room'],
            'expires_in'   => $issued['expiresIn'],
        ], [], 201);
    }

    private function authenticate(string $email, string $password): int
    {
        $userModel = model(UserModel::class);
        $user      = $userModel->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new InvalidCredentialsException();
        }

        return (int) $user['id'];
    }
}
