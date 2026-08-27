<?php

declare(strict_types=1);

namespace App\Enums;

enum InquiryStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '대기',
            self::InProgress => '처리중',
            self::Done => '완료',
        };
    }
}
