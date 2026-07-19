<?php

declare(strict_types=1);

namespace App\Exceptions;

final class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('이메일 또는 비밀번호가 올바르지 않습니다.');
    }

    public function httpStatusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'INVALID_CREDENTIALS';
    }
}
