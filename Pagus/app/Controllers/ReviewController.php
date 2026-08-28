<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RestaurantReviewService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

final class ReviewController extends Controller
{
    public function create(int $restaurantId): RedirectResponse
    {
        if (! $this->validate(['nickname' => 'required|max_length[50]', 'rating' => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[5]', 'content' => 'required|max_length[2000]', 'author_password' => 'required|min_length[8]|max_length[72]'])) {
            return redirect()->to("/restaurants/{$restaurantId}#review-form")->withInput()->with('error', '닉네임, 별점, 후기 내용을 확인하세요.');
        }
        try {
            (new RestaurantReviewService())->create($restaurantId, $this->request->getPost(), $this->reporterHash());
        } catch (InvalidArgumentException $exception) {
            return redirect()->to("/restaurants/{$restaurantId}#review-form")->withInput()->with('error', $exception->getMessage());
        }
        return redirect()->to("/restaurants/{$restaurantId}#reviews")->with('message', '후기가 등록되었습니다. 운영 검토 후 노출될 수 있습니다.');
    }

    public function update(int $restaurantId, int $reviewId): RedirectResponse
    {
        if (! $this->validate(['nickname' => 'required|max_length[50]', 'rating' => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[5]', 'content' => 'required|max_length[2000]', 'author_password' => 'required|min_length[8]|max_length[72]'])) {
            return redirect()->to("/restaurants/{$restaurantId}#reviews")->with('error', '후기 내용을 확인하세요.');
        }
        try {
            (new RestaurantReviewService())->update($restaurantId, $reviewId, $this->request->getPost(), (string) $this->request->getPost('author_password'));
        } catch (InvalidArgumentException $exception) {
            return redirect()->to("/restaurants/{$restaurantId}#reviews")->with('error', $exception->getMessage());
        }
        return redirect()->to("/restaurants/{$restaurantId}#reviews")->with('message', '후기가 수정되었습니다.');
    }

    public function delete(int $restaurantId, int $reviewId): RedirectResponse
    {
        try {
            (new RestaurantReviewService())->delete($restaurantId, $reviewId, (string) $this->request->getPost('author_password'));
        } catch (InvalidArgumentException $exception) {
            return redirect()->to("/restaurants/{$restaurantId}#reviews")->with('error', $exception->getMessage());
        }
        return redirect()->to("/restaurants/{$restaurantId}#reviews")->with('message', '후기가 삭제되었습니다.');
    }

    public function report(int $reviewId): RedirectResponse
    {
        $returnPath = (string) $this->request->getPost('return_path');
        if (! preg_match('#^/restaurants/[1-9][0-9]*$#', $returnPath)) {
            $returnPath = '/';
        }
        if (! $this->validate(['reason' => 'required|max_length[100]'])) {
            return redirect()->to($returnPath . '#reviews')->with('error', '신고 사유를 확인하세요.');
        }
        $reporterHash = hash('sha256', $this->request->getIPAddress() . '|' . (string) $this->request->getUserAgent());
        try {
            (new RestaurantReviewService())->report($reviewId, $reporterHash, (string) $this->request->getPost('reason'));
        } catch (InvalidArgumentException $exception) {
            return redirect()->to($returnPath . '#reviews')->with('error', $exception->getMessage());
        }
        return redirect()->to($returnPath . '#reviews')->with('report_message', '신고가 접수되었습니다.');
    }

    private function reporterHash(): string
    {
        return hash('sha256', $this->request->getIPAddress() . '|' . (string) $this->request->getUserAgent());
    }
}
