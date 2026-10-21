<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Jobs\RerateEnquiryLeadScoresJob;
use App\Models\GlobalSetting;
use App\Services\FileStorageService;
use App\Services\GlobalSettingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class GlobalSettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected GlobalSettingService $service,
        protected FileStorageService $fileStorage
    ) {}

    public function addSettings(Request $request)
    {
        if (! auth('api')->check()) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            $data = [];
            $leadScoreChanges = [];
            foreach ($request->all() as $key => $value) {
                if (in_array($key, ['appLogo', 'watermark'])) {
                    continue;
                }
                if ($key === 'leadScore') {
                    $map = $this->service->normalizeLeadScore($value);
                    // Diffed here, before the save, while leadScoreMap() still
                    // answers with the outgoing bands.
                    $leadScoreChanges = $this->service->leadScoreChanges($map);
                    $data[$key] = json_encode($map);

                    continue;
                }
                $data[$key] = is_array($value) ? json_encode($value) : $value;
            }
            if ($request->hasFile('appLogo')) {
                $file = $request->file('appLogo');
                $fileName = time().'-'.str_replace(' ', '', $file->getClientOriginalName());
                $data['appLogo'] = $this->fileStorage->uploadPublicFile($file, 'appLogo', $fileName);
            }
            if ($request->hasFile('watermark')) {
                $file = $request->file('watermark');
                $fileName = time().'-'.str_replace(' ', '', $file->getClientOriginalName());
                $data['watermark'] = $this->fileStorage->uploadPublicFile($file, 'watermark', $fileName);
            }
            $this->service->saveSettings($data);

            // Moving a status between bands leaves every existing enquiry on its
            // old leadRating — the observer only recomputes on a status change.
            if ($leadScoreChanges !== []) {
                RerateEnquiryLeadScoresJob::dispatch($leadScoreChanges);
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_SETTING,
                'moduleId' => 0,
                'description' => 'Updated global settings',
            ];
            logUserActivity($params);

            return response()->json([
                'status' => true,
                'message' => 'Settings saved successfully',
            ]);
        } catch (\Exception $e) {
            return $this->exceptionResponse('GlobalSettingController : addSettings', $e);
        }
    }

    public function getSettings()
    {
        if (! auth('api')->check()) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            $settings = $this->service->getSettings();

            return response()->json([
                'status' => true,
                'message' => 'Settings retrieved successfully',
                'data' => $settings,
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('GlobalSettingController : getSettings', $e);
        }
    }

    /**
     * GET /settings/email-automations — the master switch + per-automation toggle
     * states that drive the Settings → Email Automations page. Every automation
     * defaults to enabled, so an admin who never touched the page keeps today's
     * behaviour (all automations sending).
     */
    public function getEmailAutomations()
    {
        if (! auth('api')->check()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $stored = json_decode((string) globalSetting('_emailAutomations'), true);
            $stored = is_array($stored) ? $stored : [];

            // Default every known automation to true, then overlay saved values.
            $defaults = [];
            foreach (array_keys(Constants::EMAIL_AUTOMATION_SLUGS) as $key) {
                $defaults[$key] = true;
            }

            return $this->success([
                'globalEnabled' => $stored['globalEnabled'] ?? true,
                'automations' => array_merge($defaults, is_array($stored['automations'] ?? null) ? $stored['automations'] : []),
            ], 'Email automation settings retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('GlobalSettingController : getEmailAutomations', $e);
        }
    }

    /**
     * PUT /settings/email-automations — save the master switch + per-automation
     * toggles. Only known automation keys are stored; booleans are coerced.
     */
    public function updateEmailAutomations(Request $request)
    {
        if (! auth('api')->check()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $globalEnabled = filter_var($request->input('globalEnabled', true), FILTER_VALIDATE_BOOLEAN);
            $incoming = is_array($request->input('automations')) ? $request->input('automations') : [];

            $automations = [];
            foreach (array_keys(Constants::EMAIL_AUTOMATION_SLUGS) as $key) {
                $automations[$key] = filter_var($incoming[$key] ?? true, FILTER_VALIDATE_BOOLEAN);
            }

            GlobalSetting::updateOrCreate(
                ['settingKey' => '_emailAutomations'],
                [
                    'settingValue' => json_encode(['globalEnabled' => $globalEnabled, 'automations' => $automations]),
                    'createdBy' => auth('api')->id(),
                ]
            );

            return $this->success([
                'globalEnabled' => $globalEnabled,
                'automations' => $automations,
            ], 'Email automation settings updated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('GlobalSettingController : updateEmailAutomations', $e);
        }
    }
}
