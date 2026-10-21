<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GlobalSearchService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected GlobalSearchService $globalSearchService
    ) {}

    public function search(Request $request)
    {
        try {
            $results = $this->globalSearchService->search($request);

            return $this->success($results, 'Search results fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch search results', 500);
        }
    }
}
