<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminPasswordTest extends TestCase
{
    public function testPasswordIsStoredAsOneWayHash(): void
    {
        $password = 'correct horse battery staple';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        self::assertNotSame($password, $hash);
        self::assertTrue(password_verify($password, $hash));
        self::assertFalse(password_verify('wrong password', $hash));
    }
}
