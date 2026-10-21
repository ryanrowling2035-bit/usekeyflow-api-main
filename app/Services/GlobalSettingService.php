<?php

namespace App\Services;

use App\Constants\Constants;
use App\Repositories\GlobalSettingRepository;

class GlobalSettingService
{
    public function __construct(
        protected GlobalSettingRepository $repository
    ) {}

    public function saveSettings(array $data)
    {
        $userId = auth('api')->id();

        return $this->repository->saveSettings($data, $userId);
    }

    /**
     * Sanitize the incoming lead-score map before it is stored. Takes either the
     * decoded array or the JSON string form-data sends
     * ({"hot":[...],"warm":[...],"cold":[...]}), drops unknown scores/statuses,
     * gives each status to a single score (first one listed wins) and leaves a
     * score the payload omits on its current statuses.
     *
     * @param  mixed  $incoming
     * @return array<string, array<int, string>>
     */
    public function normalizeLeadScore($incoming): array
    {
        if (is_string($incoming)) {
            $incoming = json_decode($incoming, true);
        }

        $map = leadScoreMap();
        if (! is_array($incoming)) {
            return $map;
        }

        $claimed = [];
        $applied = [];

        foreach ($incoming as $score => $statuses) {
            if (! isset($map[$score]) || ! is_array($statuses)) {
                continue;
            }
            $applied[$score] = true;
            $map[$score] = [];
            foreach ($statuses as $status) {
                $status = trim((string) $status);
                if (isset($claimed[$status]) || ! isset(Constants::ENQUIRY_ALL_STATUS[$status])) {
                    continue;
                }
                $claimed[$status] = true;
                $map[$score][] = $status;
            }
        }

        // A status the payload moved is taken off the score that used to hold it.
        foreach ($map as $score => $statuses) {
            if (isset($applied[$score])) {
                continue;
            }
            $map[$score] = array_values(array_filter($statuses, fn ($status) => ! isset($claimed[$status])));
        }

        return $map;
    }

    /**
     * The statuses whose band differs between the currently effective map and
     * the one about to be saved, as status => new band (null when the status is
     * no longer scored at all). Must be called BEFORE saveSettings(), while
     * leadScoreMap() still returns the old value. An empty result means nothing
     * needs re-rating.
     *
     * @param  array<string, array<int, string>>  $newMap
     * @return array<string, string|null>
     */
    public function leadScoreChanges(array $newMap): array
    {
        $oldMap = leadScoreMap();

        $bandFor = function (array $map, string $status): ?string {
            foreach ($map as $score => $statuses) {
                if (in_array($status, $statuses, true)) {
                    return $score;
                }
            }

            return null;
        };

        $changes = [];
        foreach (array_keys(Constants::ENQUIRY_ALL_STATUS) as $status) {
            $new = $bandFor($newMap, $status);
            if ($new !== $bandFor($oldMap, $status)) {
                $changes[$status] = $new;
            }
        }

        return $changes;
    }

    public function getSettings(): array
    {
        $db = $this->repository->getAllSettings();
        $final = [];
        foreach ($db as $dbKey => $value) {
            $key = ltrim($dbKey, '_');
            if ($key === 'smtpPort') {
                $final[$key] = (int) $value;

                continue;
            }
            if (in_array($key, ['appLogo', 'watermark']) && ! empty($value)) {
                $final[$key] = getImageUrl($value);

                continue;
            }
            $decoded = json_decode($value, true);
            $final[$key] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $value;
        }

        // Always answer with the effective lead-score map, so the UI has every
        // score to render even before an admin has ever saved one.
        $final['leadScore'] = leadScoreMap();

        return $final;
    }
}
