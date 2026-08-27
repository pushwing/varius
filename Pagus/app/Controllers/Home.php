<?php

namespace App\Controllers;

use App\Services\RestaurantManagementService;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

final class Home extends BaseController
{
    private RestaurantManagementService $management;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->management = new RestaurantManagementService();
    }

    public function index(): string
    {
        $filters = RestaurantManagementService::normalizePublicFilters([
            'query' => $this->request->getGet('q'),
            'category_id' => $this->request->getGet('category'),
            'sort' => $this->request->getGet('sort'),
            'page' => $this->request->getGet('page'),
        ]);
        $result = $this->management->publicRestaurants($filters['query'], $filters['category_id'], $filters['sort'], $filters['page']);
        $categories = array_values(array_filter($this->management->categories(), static fn (array $category): bool => (int) ($category['is_active'] ?? 0) === 1));

        return view('home', ['restaurants' => $result['restaurants'], 'pager' => $result['pager'], 'categories' => $categories, 'filters' => $filters]);
    }
}
