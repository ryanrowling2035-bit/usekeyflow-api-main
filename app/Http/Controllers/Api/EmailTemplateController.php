<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmailResources\GetAllEmailResource;
use App\Http\Resources\EmailResources\GetEmailResource;
use App\Services\EmailService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmailTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected EmailService $service
    ) {}

    public function getEmailTemplates(Request $request)
    {
        try {
            $templates = $this->service->list(null);

            return $this->success(
                GetAllEmailResource::grouped($templates),
                'Email templates retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EmailTemplateController : getEmailTemplates', $e);
        }
    }

    public function addEmailTemplate(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'title' => 'required',
                'slug' => 'required|unique:manage_emails,slug',
                'subject' => 'required',
                'emailContent' => 'required',
                'macro' => 'nullable',
                'status' => 'nullable',
            ],

        );

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validatedData = $validator->validated();
            $validatedData['createdBy'] = user()->id;

            $email = $this->service->create($validatedData);

            $params = [
                'action' => 'created',
                'module' => Constants::MODULE_EMAIL_TEMPLATE,
                'moduleId' => $email->id,
                'description' => "Created email template: {$email->title}",
            ];
            logUserActivity($params);

            return $this->success(
                new GetEmailResource($email),
                'Email template created successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EmailTemplateController : addEmailTemplate', $e);
        }
    }

    public function getEmailTemplateById(int $id)
    {
        try {
            $email = $this->service->getById($id);

            if (! $email) {
                return $this->error('The requested email template was not found.', 422);
            }

            return $this->success(
                new GetEmailResource($email),
                'Email template retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EmailTemplateController : getEmailTemplateById', $e);
        }
    }

    public function updateEmailTemplateById(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required',
            // 'slug' => 'required|unique:manage_emails,slug,'.$id,
            'subject' => 'sometimes|required',
            'emailContent' => 'sometimes|required',
            // 'macro' => 'nullable',
            'status' => 'sometimes|required|string|in:'.implode(',', array_keys(Constants::USER_STATUS)),
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validatedData = $validator->validated();
            unset($validatedData['slug']);
            unset($validatedData['macro']);
            $validatedData['createdBy'] = user()->id;

            $email = $this->service->update($id, $validatedData);

            if (! $email) {
                return $this->error('The requested email template was not found.', 422);
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_EMAIL_TEMPLATE,
                'moduleId' => $email->id,
                'description' => "Updated email template: {$email->title}",
            ];
            logUserActivity($params);

            return $this->success(
                new GetEmailResource($email),
                'Email template updated successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EmailTemplateController : updateEmailTemplateById', $e);
        }
    }

    public function deleteEmailTemplate(int $id)
    {
        try {
            return $this->error('Email template deletion is currently unavailable.', 403);
        } catch (\Exception $e) {
            return $this->exceptionResponse('EmailTemplateController : deleteEmailTemplate', $e);
        }
    }
}
