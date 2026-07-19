<?php

declare(strict_types=1);

namespace App\Exceptions;

abstract class DomainException extends \RuntimeException
{
    abstract public function httpStatusCode(): int;

    abstract public function errorCode(): string;
}
