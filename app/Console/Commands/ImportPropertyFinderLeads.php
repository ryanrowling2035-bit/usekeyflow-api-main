<?php

namespace App\Console\Commands;

use App\Models\PortalSetting;
use App\Services\PropertyFinderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportPropertyFinderLeads extends Command
{
    protected $signature = 'app:import-property-finder-leads';

    protected $description = 'Fetch and import leads from every active Property Finder account (runs every 5 minutes).';

    public function handle(PropertyFinderService $propertyFinderService): int
    {
        // Shared, auto-rotating lead-import log (config/logging.php → lead_import) so
        // every run — scheduled or manual — leaves a durable trace. The `portal`
        // context tags each line so Bayut and Property Finder stay distinguishable.
        $log = Log::channel('lead_import')->withContext(['portal' => 'propertyFinder']);

        // Every Property Finder account configured in portal settings.
        $accounts = PortalSetting::whereIn('portal', ['propertyFinder', 'property-finder'])
            ->where('status', 'active')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No active Property Finder accounts found.');
            $log->info('Lead import skipped — no active accounts.');

            return self::SUCCESS;
        }

        // The window every account in this run is fetched with — logged so a thin run is
        // readable after the fact ("0 leads" because nothing came in, or because the
        // window was wrong?).
        $createdAtFrom = $propertyFinderService->leadWindowStart();

        $this->info("Importing Property Finder leads for {$accounts->count()} account(s) since {$createdAtFrom}...");
        $log->info('Lead import started', [
            'accounts' => $accounts->count(),
            'createdAtFrom' => $createdAtFrom,
        ]);

        $totalImported = 0;

        foreach ($accounts as $account) {
            // Encrypted columns decrypt automatically on access.
            $apiKey = $account->pfApiKey;
            $apiSecret = $account->pfApiSecret;

            if (! $apiKey || ! $apiSecret) {
                $this->warn("Skipping account [{$account->branchName}] — missing PF API credentials.");
                $log->warning('Account skipped — missing PF API credentials', ['branchName' => $account->branchName]);

                continue;
            }

            try {
                $leads = $propertyFinderService->getLeadByChannel($apiKey, $apiSecret);
                $count = is_array($leads) ? count($leads) : 0;
                $totalImported += $count;

                $this->info("Account [{$account->branchName}]: processed {$count} lead(s).");
                $log->info('Account processed', [
                    'branchName' => $account->branchName,
                    'leads' => $count,
                    'createdAtFrom' => $createdAtFrom,
                ]);
            } catch (\Throwable $e) {
                $log->error('Lead import failed for account', [
                    'portalSettingId' => $account->id,
                    'branchName' => $account->branchName,
                    'error' => $e->getMessage(),
                ]);

                $this->error("Account [{$account->branchName}]: import failed — {$e->getMessage()}");
            }
        }

        $this->info('Property Finder lead import complete.');
        $log->info('Lead import complete', [
            'accounts' => $accounts->count(),
            'totalLeads' => $totalImported,
            'createdAtFrom' => $createdAtFrom,
        ]);

        return self::SUCCESS;
    }
}
