<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = ['google_sub', 'email', 'name'];

    /**
     * Google 계정 sub 기준으로 사용자를 upsert 하고 내부 user_id 를 반환한다.
     *
     * 이미 있으면 email·name 을 갱신하고 기존 id 를, 없으면 새로 만들고 새 id 를 반환한다.
     */
    public function upsertByGoogleSub(string $googleSub, ?string $email, ?string $name): int
    {
        /** @var array<string, mixed>|null $existing */
        $existing = $this->where('google_sub', $googleSub)->first();

        if ($existing !== null) {
            $this->update($existing['id'], ['email' => $email, 'name' => $name]);

            return (int) $existing['id'];
        }

        return (int) $this->insert([
            'google_sub' => $googleSub,
            'email' => $email,
            'name' => $name,
        ], true);
    }
}
