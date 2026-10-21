<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeadSourceResource\LeadSourceResource;
use App\Services\LeadSourceService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LeadSourceController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected LeadSourceService $leadSourceService
    ) {}

    public function addLeadSource(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validatedData = $validator->validated();

            $result = $this->leadSourceService->create($validatedData);

            if (isset($result['error'])) {
                return $this->error($result['error'], 422);
            }

            return $this->success(
                new LeadSourceResource($result),
                'Lead source created successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('LeadSourceController : addLeadSource', $e);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $data = $this->leadSourceService->getAll($request);

            return $this->success(
                LeadSourceResource::collection($data),
                'Lead sources retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('LeadSourceController : getAll', $e);
        }
    }

    public function select()
    {
        try {
            $data = $this->leadSourceService->select();

            return $this->success($data, 'Lead sources retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('LeadSourceController : select', $e);
        }
    }

    public function getById($id)
    {
        try {
            $data = $this->leadSourceService->getById($id);

            if (! $data) {
                return $this->error('Lead source not found', 422);
            }

            return $this->success(
                new LeadSourceResource($data),
                'Lead source retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('LeadSourceController : getById', $e);
        }
    }

    public function updateLeadSource(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validatedData = $validator->validated();

            $result = $this->leadSourceService->update($id, $validatedData);
            if (is_array($result) && isset($result['error'])) {
                return $this->error($result['error'], 422);
            }

            if (! $result) {
                return $this->error('Lead source not found', 404);
            }

            return $this->success([], 'Lead source updated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('LeadSourceController : updateLeadSource', $e, 500);
        }
    }

    public function deleteLeadSource($id)
    {
        try {
            $result = $this->leadSourceService->delete($id);

            if (is_array($result) && isset($result['error'])) {
                return $this->error($result['error'], 422);
            }
            if (! $result) {
                return $this->error('Lead source not found', 404);
            }

            return $this->success([], 'Lead source deleted successfully');
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
