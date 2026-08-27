<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InquiryService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InquiryServiceTest extends TestCase
{
    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InquiryService::assertInquiryData(['name' => '', 'message' => '문의 내용입니다.']);
    }

    public function testEmptyMessageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InquiryService::assertInquiryData(['name' => '홍길동', 'message' => '']);
    }

    public function testNameOver100CharsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InquiryService::assertInquiryData(['name' => str_repeat('가', 101), 'message' => '문의 내용입니다.']);
    }

    public function testMessageOver2000CharsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InquiryService::assertInquiryData(['name' => '홍길동', 'message' => str_repeat('가', 2001)]);
    }

    public function testContactOver255CharsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InquiryService::assertInquiryData(['name' => '홍길동', 'message' => '문의 내용입니다.', 'contact' => str_repeat('1', 256)]);
    }

    public function testValidDataWithoutContactIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        InquiryService::assertInquiryData(['name' => '홍길동', 'message' => '문의 내용입니다.']);
    }

    public function testValidDataWithContactIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        InquiryService::assertInquiryData(['name' => '홍길동', 'message' => '문의 내용입니다.', 'contact' => '010-1234-5678']);
    }
}
