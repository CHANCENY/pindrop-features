<?php

namespace Simp\Pindrop\Modules\admin\src\Plugin;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Routing\RouteManager;

class AutocompleteInternal
{

    public function __construct(protected DatabaseService $database)
    {
    }

    /**
     * @throws DatabaseException
     */
    public function matchUsers(string $query, int $limit = 10, $sort = 'DESC', $sort_by = "created_at"): array
    {

        if (empty($limit)) {
            $limit = 10;
        }

        if (empty($sort)) {
            $sort = "DESC";
        }
        if (empty($sort_by)) {
            $sort_by = "created_at";
        }
        $results = $this->database->table("users")->whereRaw("username LIKE :q1 OR email LIKE :q2", [
            'q1' => "%$query%",
            'q2' => "%$query%"
        ])->orderBy($sort_by, $sort)->limit($limit)->get();



        return array_map(function ($result) {
            return [
                'value' => "{$result['email']} ({$result['id']})",
                'label' => "{$result['email']} ({$result['id']})"
            ];
        }, $results);
    }

    public function matchRoutes(string $query, int $limit = 10, $sort = 'DESC', $sort_by = null): array
    {
        $listedRoutes = RouteManager::getAllRoutes();
        $routes = array_filter($listedRoutes, function ($route) use ($query) {
            return !str_contains($route['path'], "[") && str_contains($route['route_name'], $query);
        });

        return array_map(function ($result) {
            return [
                'value' => "{$result['path']} ({$result['route_name']})",
                'label' => "{$result['path']} ({$result['route_name']})"
            ];
        }, array_slice($routes, 0, $limit));
    }
}