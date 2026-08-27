<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InquiryStatus;
use App\Models\InquiryModel;
use InvalidArgumentException;

final class InquiryService
{
    public function __construct(private readonly ?InquiryModel $inquiries = null)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return ($this->inquiries ?? model(InquiryModel::class))->orderBy('created_at', 'DESC')->findAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $inquiry = ($this->inquiries ?? model(InquiryModel::class))->find($id);
        return is_array($inquiry) ? $inquiry : null;
    }

    /** @param array<string, mixed> $data */
    public function submit(array $data): int
    {
        self::assertInquiryData($data);
        $model = $this->inquiries ?? model(InquiryModel::class);
        $contact = trim((string) ($data['contact'] ?? ''));
        $model->insert([
            'name' => trim((string) $data['name']),
            'contact' => $contact === '' ? null : $contact,
            'message' => trim((string) $data['message']),
            'status' => InquiryStatus::Pending->value,
        ]);
        return (int) $model->getInsertID();
    }

    public function updateStatus(int $id, string $status): void
    {
        $case = InquiryStatus::tryFrom($status);
        if ($case === null) {
            throw new InvalidArgumentException('처리 상태 값이 올바르지 않습니다.');
        }
        $model = $this->inquiries ?? model(InquiryModel::class);
        if (! is_array($model->find($id))) {
            throw new InvalidArgumentException('문의를 찾을 수 없습니다.');
        }
        $model->update($id, ['status' => $case->value]);
    }

    /** @param array<string, mixed> $data */
    public static function assertInquiryData(array $data): void
    {
        if (! isset($data['name']) || trim((string) $data['name']) === '') {
            throw new InvalidArgumentException('이름을 입력하세요.');
        }
        if (! isset($data['message']) || trim((string) $data['message']) === '') {
            throw new InvalidArgumentException('문의 내용을 입력하세요.');
        }
        if (mb_strlen(trim((string) $data['name'])) > 100) {
            throw new InvalidArgumentException('이름은 100자 이하로 입력하세요.');
        }
        if (mb_strlen(trim((string) $data['message'])) > 2000) {
            throw new InvalidArgumentException('문의 내용은 2000자 이하로 입력하세요.');
        }
        if (isset($data['contact']) && mb_strlen(trim((string) $data['contact'])) > 255) {
            throw new InvalidArgumentException('연락처는 255자 이하로 입력하세요.');
        }
    }
}
