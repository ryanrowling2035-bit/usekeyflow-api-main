<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;

class GlobalSettingRepository
{
    public function saveSettings(array $data, $userId)
    {
        foreach ($data as $key => $value) {
            if (! isset(Constants::GLOBAL_SETTINGS[$key])) {
                continue;
            }
            $dbKey = Constants::GLOBAL_SETTINGS[$key];
            GlobalSetting::updateOrCreate(
                ['settingKey' => $dbKey],
                [
                    'settingValue' => $value,
                    'createdBy' => $userId,
                ]
            );

            // Drop the cached lead-score map so every process (web + queue
            // workers) sees the new bands on its next read.
            if ($dbKey === '_leadScore') {
                Cache::forget(Constants::LEAD_SCORE_CACHE_KEY);
            }
        }

        return true;
    }

    public function getAllSettings()
    {
        return GlobalSetting::pluck('settingValue', 'settingKey')->toArray();
    }
}
