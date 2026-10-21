<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserActivityResource;
use App\Services\UserActivityService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class UserActivityController extends Controller
{
    use ApiResponse;

    protected UserActivityService $activityService;

    public function __construct(UserActivityService $activityService)
    {
        $this->activityService = $activityService;
    }

    public function index(Request $request)
    {
        try {
            $activities = $this->activityService->getAll($request);

            return $this->success(
                UserActivityResource::collection($activities),
                'Activities retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('UserActivityController : index', $e);
        }
    }

    public function getByModule(Request $request, string $module, int $moduleId)
    {
        try {
            $request->merge([
                'module' => $module,
                'moduleId' => $moduleId,
            ]);

            $activities = $this->activityService->getAll($request);

            return $this->success(
                UserActivityResource::collection($activities),
                'Module activities retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('UserActivityController : getByModule', $e);
        }
    }
}
