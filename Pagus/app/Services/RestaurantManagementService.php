<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CategoryModel;
use App\Models\RestaurantModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Pager\Pager;
use InvalidArgumentException;
use RuntimeException;

final class RestaurantManagementService
{
    public function __construct(private readonly ?CategoryModel $categories = null, private readonly ?RestaurantModel $restaurants = null, private readonly ?BaseConnection $db = null)
    {
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return ($this->categories ?? model(CategoryModel::class))->orderBy('name', 'ASC')->findAll();
    }

    /** @return array<string, mixed>|null */
    public function category(int $id): ?array
    {
        $category = ($this->categories ?? model(CategoryModel::class))->find($id);
        return is_array($category) ? $category : null;
    }

    /** @return list<array<string, mixed>> */
    public function restaurants(string $query = ''): array
    {
        $model = ($this->restaurants ?? model(RestaurantModel::class))->select('restaurants.*, GROUP_CONCAT(categories.name ORDER BY categories.name SEPARATOR ", ") AS category_names')->join('restaurant_categories', 'restaurant_categories.restaurant_id = restaurants.id', 'left')->join('categories', 'categories.id = restaurant_categories.category_id', 'left')->groupBy('restaurants.id')->orderBy('restaurants.name', 'ASC');
        if ($query !== '') {
            $model->groupStart()->like('restaurants.name', $query)->orLike('restaurants.address', $query)->orLike('restaurants.tags', $query)->groupEnd();
        }
        return $model->findAll();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{query: string, category_id: ?int, sort: string, page: int}
     */
    public static function normalizePublicFilters(array $filters): array
    {
        $query = trim((string) ($filters['query'] ?? ''));
        if (mb_strlen($query) > 100) {
            $query = mb_substr($query, 0, 100);
        }

        $categoryId = filter_var($filters['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sort = in_array($filters['sort'] ?? '', ['name', 'newest'], true) ? (string) $filters['sort'] : 'name';
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;

        return ['query' => $query, 'category_id' => is_int($categoryId) ? $categoryId : null, 'sort' => $sort, 'page' => $page];
    }

    /**
     * @return array{restaurants: list<array<string, mixed>>, pager: Pager}
     */
    public function publicRestaurants(string $query = '', ?int $categoryId = null, string $sort = 'name', int $page = 1): array
    {
        $model = ($this->restaurants ?? model(RestaurantModel::class))
            ->select('restaurants.*, GROUP_CONCAT(DISTINCT categories.name ORDER BY categories.name SEPARATOR ", ") AS category_names')
            ->join('restaurant_categories', 'restaurant_categories.restaurant_id = restaurants.id', 'left')
            ->join('categories', 'categories.id = restaurant_categories.category_id', 'left')
            ->where('restaurants.is_published', 1)
            ->groupBy('restaurants.id');

        if ($categoryId !== null) {
            $model->where('categories.id', $categoryId)->where('categories.is_active', 1);
        }
        if ($query !== '') {
            $model->groupStart()
                ->like('restaurants.name', $query)
                ->orLike('restaurants.address', $query)
                ->orLike('restaurants.tags', $query)
                ->orLike('categories.name', $query)
                ->groupEnd();
        }

        if ($sort === 'newest') {
            $model->orderBy('restaurants.created_at', 'DESC')->orderBy('restaurants.id', 'DESC');
        } else {
            $model->orderBy('restaurants.name', 'ASC');
        }

        return ['restaurants' => $model->paginate(8, 'restaurants', $page), 'pager' => $model->pager];
    }

    /** @return array<string, mixed>|null 공개된 맛집만 조회한다 */
    public function publicRestaurant(int $id): ?array
    {
        $restaurant = ($this->restaurants ?? model(RestaurantModel::class))
            ->select('restaurants.*, GROUP_CONCAT(DISTINCT categories.name ORDER BY categories.name SEPARATOR ", ") AS category_names')
            ->join('restaurant_categories', 'restaurant_categories.restaurant_id = restaurants.id', 'left')
            ->join('categories', 'categories.id = restaurant_categories.category_id', 'left')
            ->where('restaurants.id', $id)
            ->where('restaurants.is_published', 1)
            ->groupBy('restaurants.id')
            ->first();
        return is_array($restaurant) ? $restaurant : null;
    }

    /** @return array<string, mixed>|null */
    public function restaurant(int $id): ?array
    {
        $restaurant = ($this->restaurants ?? model(RestaurantModel::class))->find($id);
        if (! is_array($restaurant)) {
            return null;
        }
        $restaurant['category_ids'] = array_map(static fn (array $row): int => (int) $row['category_id'], db_connect()->table('restaurant_categories')->select('category_id')->where('restaurant_id', $id)->get()->getResultArray());
        return $restaurant;
    }

    public function saveCategory(?int $id, string $name, bool $isActive = true): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('카테고리명은 필수입니다.');
        }
        $model = $this->categories ?? model(CategoryModel::class);
        $data = ['name' => $name, 'is_active' => $isActive ? 1 : 0];
        if ($id === null) {
            $model->insert($data);
            return (int) $model->getInsertID();
        }
        $model->update($id, $data);
        return $id;
    }

    public function toggleCategory(int $id): void
    {
        $model = $this->categories ?? model(CategoryModel::class);
        $category = $model->find($id);
        if (! is_array($category)) {
            throw new InvalidArgumentException('카테고리를 찾을 수 없습니다.');
        }
        $model->update($id, ['is_active' => ((int) $category['is_active']) === 1 ? 0 : 1]);
    }

    /** @param array<string, mixed> $data @param list<int> $categoryIds */
    public function saveRestaurant(?int $id, array $data, array $categoryIds): int
    {
        self::assertRestaurantData($data);
        $restaurantModel = $this->restaurants ?? model(RestaurantModel::class);
        $db = $this->db ?? db_connect();
        $db->transStart();
        if ($id === null) {
            $restaurantModel->insert($data);
            $id = (int) $restaurantModel->getInsertID();
        } else {
            if (! is_array($restaurantModel->find($id))) {
                throw new InvalidArgumentException('맛집을 찾을 수 없습니다.');
            }
            $restaurantModel->update($id, $data);
            $db->table('restaurant_categories')->where('restaurant_id', $id)->delete();
        }
        foreach (array_values(array_unique(array_map('intval', $categoryIds))) as $categoryId) {
            $db->table('restaurant_categories')->insert(['restaurant_id' => $id, 'category_id' => $categoryId]);
        }
        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new RuntimeException('맛집 저장에 실패했습니다.');
        }
        return $id;
    }

    public function toggleRestaurant(int $id): void
    {
        $model = $this->restaurants ?? model(RestaurantModel::class);
        $restaurant = $model->find($id);
        if (! is_array($restaurant)) {
            throw new InvalidArgumentException('맛집을 찾을 수 없습니다.');
        }
        $model->update($id, ['is_published' => ((int) $restaurant['is_published']) === 1 ? 0 : 1]);
    }

    /** @param array<string, mixed> $data */
    public static function assertRestaurantData(array $data): void
    {
        foreach (['name', 'address', 'latitude', 'longitude'] as $field) {
            if (! isset($data[$field]) || trim((string) $data[$field]) === '') {
                throw new InvalidArgumentException('맛집 필수값이 누락되었습니다.');
            }
        }
        if ((float) $data['latitude'] < -90 || (float) $data['latitude'] > 90 || (float) $data['longitude'] < -180 || (float) $data['longitude'] > 180) {
            throw new InvalidArgumentException('좌표 범위가 올바르지 않습니다.');
        }
    }
}
