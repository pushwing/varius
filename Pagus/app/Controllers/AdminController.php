<?php

namespace App\Controllers;

use App\Enums\InquiryStatus;
use App\Services\InquiryService;
use App\Services\RestaurantManagementService;
use App\Services\RestaurantPhotoService;
use App\Services\RestaurantReviewService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use App\Services\GeocodingService;
use App\Services\KakaoLocalReferenceService;
use InvalidArgumentException;
use RuntimeException;

final class AdminController extends Controller
{
    private RestaurantManagementService $management;
    private RestaurantPhotoService $photos;
    private GeocodingService $geocoding;
    private KakaoLocalReferenceService $reference;
    private InquiryService $inquiries;
    private RestaurantReviewService $reviews;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->management = new RestaurantManagementService();
        $this->photos = new RestaurantPhotoService();
        $this->geocoding = new GeocodingService();
        $this->reference = new KakaoLocalReferenceService();
        $this->inquiries = new InquiryService();
        $this->reviews = new RestaurantReviewService();
    }

    public function index(): string
    {
        return view('admin/restaurants', ['restaurants' => $this->management->restaurants((string) $this->request->getGet('q'))]);
    }

    public function newRestaurant(): string
    {
        return view('admin/restaurant_form', ['categories' => $this->management->categories(), 'editingRestaurant' => null]);
    }

    public function editRestaurant(int $id): string|RedirectResponse
    {
        $restaurant = $this->management->restaurant($id);
        if ($restaurant === null) {
            return redirect()->to('/admin')->with('error', '맛집을 찾을 수 없습니다.');
        }
        return view('admin/restaurant_form', ['categories' => $this->management->categories(), 'editingRestaurant' => $restaurant]);
    }

    public function categories(): string
    {
        return view('admin/categories', ['categories' => $this->management->categories(), 'editingCategory' => null]);
    }

    public function editCategory(int $id): string|RedirectResponse
    {
        $category = $this->management->category($id);
        if ($category === null) {
            return redirect()->to('/admin/categories')->with('error', '카테고리를 찾을 수 없습니다.');
        }
        return view('admin/categories', ['categories' => $this->management->categories(), 'editingCategory' => $category]);
    }

    public function saveCategory(): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[100]'])) {
            return redirect()->to('/admin/categories')->withInput()->with('error', '카테고리명을 확인하세요.');
        }
        try {
            $id = $this->request->getPost('id');
            $this->management->saveCategory($id === null || $id === '' ? null : (int) $id, (string) $this->request->getPost('name'));
        } catch (InvalidArgumentException $exception) {
            return redirect()->to('/admin/categories')->withInput()->with('error', $exception->getMessage());
        }
        return redirect()->to('/admin/categories')->with('message', '카테고리를 저장했습니다.');
    }

    public function toggleCategory(int $id): RedirectResponse
    {
        $this->management->toggleCategory($id);
        return redirect()->to('/admin/categories')->with('message', '카테고리 상태를 변경했습니다.');
    }

    public function saveRestaurant(): RedirectResponse
    {
        $post = $this->request->getPost();
        $formUrl = ($post['id'] ?? '') === '' ? '/admin/restaurants/new' : "/admin/restaurants/{$post['id']}/edit";
        $rules = ['name' => 'required|max_length[150]', 'address' => 'required|max_length[255]', 'latitude' => 'required|numeric|greater_than_equal_to[-90]|less_than_equal_to[90]', 'longitude' => 'required|numeric|greater_than_equal_to[-180]|less_than_equal_to[180]', 'phone' => 'permit_empty|max_length[30]', 'homepage_url' => 'permit_empty|valid_url|max_length[2048]', 'description' => 'permit_empty', 'menu' => 'permit_empty', 'business_hours' => 'permit_empty', 'tags' => 'permit_empty|max_length[500]'];
        if (! $this->validate($rules)) {
            return redirect()->to($formUrl)->withInput()->with('error', '맛집 필수값 또는 좌표를 확인하세요.');
        }
        $data = array_intersect_key($post, array_flip(['name', 'address', 'latitude', 'longitude', 'phone', 'homepage_url', 'description', 'menu', 'business_hours', 'tags']));
        $data['is_published'] = ((string) $this->request->getPost('is_published')) === '1' ? 1 : 0;
        try {
            $id = ($post['id'] ?? '') === '' ? null : (int) $post['id'];
            $this->management->saveRestaurant($id, $data, array_map('intval', (array) ($post['category_ids'] ?? [])));
        } catch (InvalidArgumentException $exception) {
            return redirect()->to($formUrl)->withInput()->with('error', $exception->getMessage());
        }
        return redirect()->to('/admin')->with('message', '맛집을 저장했습니다.');
    }

    public function toggleRestaurant(int $id): RedirectResponse
    {
        $this->management->toggleRestaurant($id);
        return redirect()->to('/admin')->with('message', '맛집 공개 상태를 변경했습니다.');
    }

    public function managePhotos(int $restaurantId): string|RedirectResponse
    {
        $restaurant = $this->management->restaurant($restaurantId);
        if ($restaurant === null) {
            return redirect()->to('/admin')->with('error', '맛집을 찾을 수 없습니다.');
        }
        return view('admin/photos', ['restaurant' => $restaurant, 'photos' => $this->photos->photosForRestaurant($restaurantId, true)]);
    }

    public function uploadPhotos(int $restaurantId): RedirectResponse
    {
        $files = array_values(array_filter(
            (array) ($this->request->getFiles()['photos'] ?? []),
            static fn (mixed $file): bool => $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE,
        ));
        try {
            $uploaded = $this->photos->uploadPhotos($restaurantId, $files);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return redirect()->to("/admin/restaurants/{$restaurantId}/photos")->with('error', $exception->getMessage());
        }
        return redirect()->to("/admin/restaurants/{$restaurantId}/photos")->with('message', "{$uploaded}장의 사진을 업로드했습니다.");
    }

    public function togglePhoto(int $restaurantId, int $photoId): RedirectResponse
    {
        try {
            $this->photos->togglePhoto($restaurantId, $photoId);
        } catch (InvalidArgumentException $exception) {
            return redirect()->to("/admin/restaurants/{$restaurantId}/photos")->with('error', $exception->getMessage());
        }
        return redirect()->to("/admin/restaurants/{$restaurantId}/photos")->with('message', '사진 공개 상태를 변경했습니다.');
    }

    public function deletePhoto(int $restaurantId, int $photoId): RedirectResponse
    {
        try {
            $this->photos->deletePhoto($restaurantId, $photoId);
        } catch (InvalidArgumentException $exception) {
            return redirect()->to("/admin/restaurants/{$restaurantId}/photos")->with('error', $exception->getMessage());
        }
        return redirect()->to("/admin/restaurants/{$restaurantId}/photos")->with('message', '사진을 삭제했습니다.');
    }

    public function searchAddress(): ResponseInterface
    {
        $query = trim((string) $this->request->getGet('q'));
        if ($query === '' || mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return $this->response->setStatusCode(400)->setJSON(['error' => '주소 검색어는 2자 이상 100자 이하로 입력하세요.']);
        }
        $results = $this->geocoding->search($query);
        if ($results === null) {
            return $this->response->setStatusCode(503)->setJSON(['error' => '주소 검색을 사용할 수 없습니다. 주소와 좌표를 직접 입력하세요.']);
        }
        return $this->response->setJSON(['results' => $results]);
    }

    public function searchReference(): ResponseInterface
    {
        $query = trim((string) $this->request->getGet('q'));
        if ($query === '' || mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return $this->response->setStatusCode(400)->setJSON(['error' => '참고 데이터 검색어는 2자 이상 100자 이하로 입력하세요.']);
        }
        $results = $this->reference->search($query);
        if ($results === null) {
            return $this->response->setStatusCode(503)->setJSON(['error' => '참고 데이터 조회를 사용할 수 없습니다. 상호·주소·좌표를 직접 입력하세요.']);
        }
        return $this->response->setJSON(['results' => $results]);
    }

    public function inquiries(): string
    {
        return view('admin/inquiries', ['inquiries' => $this->inquiries->all()]);
    }

    public function reviews(): string
    {
        return view('admin/reviews', ['reviews' => $this->reviews->all()]);
    }

    public function toggleReview(int $id): RedirectResponse
    {
        try {
            $this->reviews->toggleHidden($id);
        } catch (InvalidArgumentException $exception) {
            return redirect()->to('/admin/reviews')->with('error', $exception->getMessage());
        }
        return redirect()->to('/admin/reviews')->with('message', '후기 공개 상태를 변경했습니다.');
    }

    public function showInquiry(int $id): string|RedirectResponse
    {
        $inquiry = $this->inquiries->find($id);
        if ($inquiry === null) {
            return redirect()->to('/admin/inquiries')->with('error', '문의를 찾을 수 없습니다.');
        }
        return view('admin/inquiry', ['inquiry' => $inquiry, 'statuses' => InquiryStatus::cases()]);
    }

    public function updateInquiryStatus(int $id): RedirectResponse
    {
        try {
            $this->inquiries->updateStatus($id, (string) $this->request->getPost('status'));
        } catch (InvalidArgumentException $exception) {
            return redirect()->to("/admin/inquiries/{$id}")->with('error', $exception->getMessage());
        }
        return redirect()->to("/admin/inquiries/{$id}")->with('message', '처리 상태를 변경했습니다.');
    }
}
