<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortalSettingResource\PortalSettingResource;
use App\Services\PortalSettingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PortalSettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PortalSettingService $service
    ) {}

    public function addPortalSetting(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'portal' => 'required|string|max:50',
            'branchName' => 'required|string|max:255',
            'bayutSecretToken' => 'nullable|string',
            'pfApiKey' => 'nullable|string',
            'pfApiSecret' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $result = $this->service->create($validator->validated());

            if (is_array($result) && isset($result['error'])) {
                return $this->error($result['error'], 422);
            }

            return $this->success(
                new PortalSettingResource($result),
                'Portal setting created successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PortalSettingController : addPortalSetting', $e);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $data = $this->service->getAll($request);

            return $this->success(
                PortalSettingResource::collection($data),
                'Portal settings retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PortalSettingController : getAll', $e);
        }
    }

    public function getById($id)
    {
        try {
            $setting = $this->service->getById($id);

            if (! $setting) {
                return $this->error('Portal setting not found', 404);
            }

            return $this->success(
                new PortalSettingResource($setting),
                'Portal setting retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PortalSettingController : getById', $e);
        }
    }

    public function updatePortalSetting(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'portal' => 'sometimes|required|string|max:50',
            'branchName' => 'sometimes|required|string|max:255',
            'bayutSecretToken' => 'nullable|string',
            'pfApiKey' => 'nullable|string',
            'pfApiSecret' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $result = $this->service->update($id, $validator->validated());

            if (is_array($result) && isset($result['error'])) {
                return $this->error($result['error'], 422);
            }

            if (! $result) {
                return $this->error('Portal setting not found', 404);
            }

            return $this->success(
                new PortalSettingResource($result),
                'Portal setting updated successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PortalSettingController : updatePortalSetting', $e);
        }
    }

    public function deletePortalSetting($id)
    {
        try {
            $result = $this->service->delete($id);

            if (! $result) {
                return $this->error('Portal setting not found', 404);
            }

            return $this->success([], 'Portal setting deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('PortalSettingController : deletePortalSetting', $e);
        }
    }
}
