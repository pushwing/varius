<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RestaurantModel;
use App\Models\RestaurantReviewModel;
use App\Models\RestaurantReviewReportModel;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

final class RestaurantReviewService
{
    private const MAX_REPORTS_PER_DAY = 5;
    private const AUTO_HIDE_REPORT_COUNT = 3;

    public function __construct(private readonly ?RestaurantReviewModel $reviews = null, private readonly ?RestaurantReviewReportModel $reports = null, private readonly ?RestaurantModel $restaurants = null, private readonly ?BaseConnection $db = null)
    {
    }

    /** @return list<array<string, mixed>> */
    public function publicForRestaurant(int $restaurantId): array
    {
        return ($this->reviews ?? model(RestaurantReviewModel::class))->where('restaurant_id', $restaurantId)->where('is_hidden', 0)->orderBy('created_at', 'DESC')->findAll();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return ($this->reviews ?? model(RestaurantReviewModel::class))->select('restaurant_reviews.*, restaurants.name AS restaurant_name')->join('restaurants', 'restaurants.id = restaurant_reviews.restaurant_id')->orderBy('restaurant_reviews.created_at', 'DESC')->findAll();
    }

    /** @param array<string, mixed> $data */
    public function create(int $restaurantId, array $data, string $authorReporterHash): int
    {
        self::assertReviewData($data);
        $restaurant = ($this->restaurants ?? model(RestaurantModel::class))->find($restaurantId);
        if (! is_array($restaurant) || (int) ($restaurant['is_published'] ?? 0) !== 1) {
            throw new InvalidArgumentException('후기를 작성할 맛집을 찾을 수 없습니다.');
        }
        self::assertAuthorPassword((string) ($data['author_password'] ?? ''));
        $model = $this->reviews ?? model(RestaurantReviewModel::class);
        if (! preg_match('/^[a-f0-9]{64}$/', $authorReporterHash)) {
            throw new InvalidArgumentException('작성자 식별값이 올바르지 않습니다.');
        }
        $model->insert(['restaurant_id' => $restaurantId, 'nickname' => trim((string) $data['nickname']), 'rating' => (int) $data['rating'], 'content' => trim((string) $data['content']), 'author_password_hash' => password_hash((string) $data['author_password'], PASSWORD_DEFAULT), 'author_reporter_hash' => $authorReporterHash]);
        return (int) $model->getInsertID();
    }

    /** @param array<string, mixed> $data */
    public function update(int $restaurantId, int $reviewId, array $data, string $authorPassword): void
    {
        self::assertReviewData($data);
        $review = $this->ownedReview($restaurantId, $reviewId);
        self::assertAuthorPassword($authorPassword);
        if (! password_verify($authorPassword, (string) ($review['author_password_hash'] ?? ''))) {
            throw new InvalidArgumentException('후기 비밀번호가 올바르지 않습니다.');
        }
        ($this->reviews ?? model(RestaurantReviewModel::class))->update($reviewId, ['nickname' => trim((string) $data['nickname']), 'rating' => (int) $data['rating'], 'content' => trim((string) $data['content'])]);
    }

    public function delete(int $restaurantId, int $reviewId, string $authorPassword): void
    {
        $review = $this->ownedReview($restaurantId, $reviewId);
        self::assertAuthorPassword($authorPassword);
        if (! password_verify($authorPassword, (string) ($review['author_password_hash'] ?? ''))) {
            throw new InvalidArgumentException('후기 비밀번호가 올바르지 않습니다.');
        }
        ($this->reviews ?? model(RestaurantReviewModel::class))->delete($reviewId);
    }

    public function report(int $reviewId, string $reporterHash, string $reason): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $reporterHash)) {
            throw new InvalidArgumentException('신고자 식별값이 올바르지 않습니다.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 100) {
            throw new InvalidArgumentException('신고 사유를 확인하세요.');
        }
        $reviews = $this->reviews ?? model(RestaurantReviewModel::class);
        $review = $reviews->find($reviewId);
        if (! is_array($review)) {
            throw new InvalidArgumentException('후기를 찾을 수 없습니다.');
        }
        if ((string) ($review['author_reporter_hash'] ?? '') === $reporterHash) {
            throw new InvalidArgumentException('내가 작성한 후기는 신고할 수 없습니다.');
        }
        $reports = $this->reports ?? model(RestaurantReviewReportModel::class);
        if ($reports->where('review_id', $reviewId)->where('reporter_hash', $reporterHash)->first() !== null) {
            throw new InvalidArgumentException('이미 신고한 후기입니다.');
        }
        $since = date('Y-m-d H:i:s', time() - 86400);
        if ($reports->where('reporter_hash', $reporterHash)->where('created_at >=', $since)->countAllResults() >= self::MAX_REPORTS_PER_DAY) {
            throw new InvalidArgumentException('하루 신고 가능 횟수를 초과했습니다.');
        }
        $db = $this->db ?? db_connect();
        $db->transStart();
        $reports->insert(['review_id' => $reviewId, 'reporter_hash' => $reporterHash, 'reason' => $reason, 'created_at' => date('Y-m-d H:i:s')]);
        $reportCount = (int) ($review['report_count'] ?? 0) + 1;
        $reviews->update($reviewId, ['report_count' => $reportCount, 'is_hidden' => $reportCount >= self::AUTO_HIDE_REPORT_COUNT ? 1 : (int) ($review['is_hidden'] ?? 0)]);
        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new InvalidArgumentException('신고를 처리하지 못했습니다.');
        }
    }

    public function toggleHidden(int $id): void
    {
        $model = $this->reviews ?? model(RestaurantReviewModel::class);
        $review = $model->find($id);
        if (! is_array($review)) {
            throw new InvalidArgumentException('후기를 찾을 수 없습니다.');
        }
        $model->update($id, ['is_hidden' => (int) $review['is_hidden'] === 1 ? 0 : 1]);
    }

    /** @param array<string, mixed> $data */
    public static function assertReviewData(array $data): void
    {
        $nickname = trim((string) ($data['nickname'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        $rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);
        if ($nickname === '' || mb_strlen($nickname) > 50) {
            throw new InvalidArgumentException('닉네임은 1자 이상 50자 이하로 입력하세요.');
        }
        if ($content === '' || mb_strlen($content) > 2000) {
            throw new InvalidArgumentException('후기 내용은 1자 이상 2000자 이하로 입력하세요.');
        }
        if ($rating === false || $rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('별점은 1점부터 5점까지 선택하세요.');
        }
    }

    public static function assertAuthorPassword(string $password): void
    {
        if (mb_strlen($password) < 8 || mb_strlen($password) > 72) {
            throw new InvalidArgumentException('후기 비밀번호는 8자 이상 72자 이하로 입력하세요.');
        }
    }

    /** @return array<string, mixed> */
    private function ownedReview(int $restaurantId, int $reviewId): array
    {
        $review = ($this->reviews ?? model(RestaurantReviewModel::class))->find($reviewId);
        if (! is_array($review) || (int) ($review['restaurant_id'] ?? 0) !== $restaurantId) {
            throw new InvalidArgumentException('후기를 찾을 수 없습니다.');
        }
        return $review;
    }
}
