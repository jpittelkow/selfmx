<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private SearchService $searchService
    ) {}

    /**
     * Global search across searchable models.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', 'string', 'in:users,user_groups,notifications,email_templates,notification_templates,api_tokens,ai_providers,webhooks,emails,contacts'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $request->input('q', '');
        $type = $request->input('type');
        $page = (int) $request->input('page', 1);
        $perPage = $request->input('per_page') ? (int) $request->input('per_page') : null;

        $scopeUserId = $request->user()->inGroup('admin') ? null : $request->user()->id;

        $result = $this->searchService->globalSearch(
            $query,
            $type,
            [],
            $page,
            $perPage,
            $scopeUserId
        );

        return $this->dataResponse($result);
    }

    /**
     * Get search suggestions (autocomplete).
     */
    public function suggestions(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $query = $request->input('q', '');
        $limit = (int) $request->input('limit', 5);
        $scopeUserId = $request->user()->inGroup('admin') ? null : $request->user()->id;

        $data = $this->searchService->getSuggestions($query, $limit, $scopeUserId);

        return $this->dataResponse(['data' => $data]);
    }
}
