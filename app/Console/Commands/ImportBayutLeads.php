<?php

namespace App\Console\Commands;

use App\Models\PortalSetting;
use App\Services\BayutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportBayutLeads extends Command
{
    protected $signature = 'app:import-bayut-leads';

    protected $description = 'Fetch and import leads from every active Bayut account (runs every 5 minutes).';

    public function handle(BayutService $bayutService): int
    {
        // Shared, auto-rotating lead-import log (config/logging.php → lead_import) so
        // every run — scheduled or manual — leaves a durable trace. The `portal`
        // context tags each line so Bayut and Property Finder stay distinguishable.
        $log = Log::channel('lead_import')->withContext(['portal' => 'bayut']);

        // Every Bayut account configured in portal settings.
        $accounts = PortalSetting::where('portal', 'bayut')
            ->where('status', 'active')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No active Bayut accounts found.');
            $log->info('Lead import skipped — no active accounts.');

            return self::SUCCESS;
        }

        // The window every account in this run is fetched with — logged so a thin run is
        // readable after the fact ("0 leads" because nothing came in, or because the
        // window was wrong?).
        $createdFrom = $bayutService->leadWindowStart();

        $this->info("Importing Bayut leads for {$accounts->count()} account(s) since {$createdFrom}...");
        $log->info('Lead import started', [
            'accounts' => $accounts->count(),
            'createdFrom' => $createdFrom,
        ]);

        $totalImported = 0;

        foreach ($accounts as $account) {
            // Encrypted column decrypts automatically on access.
            $token = $account->bayutSecretToken;

            if (! $token) {
                $this->warn("Skipping account [{$account->branchName}] — missing Bayut secret token.");
                $log->warning('Account skipped — missing secret token', ['branchName' => $account->branchName]);

                continue;
            }

            try {
                $leads = $bayutService->getLeads($token);
                $count = is_array($leads) ? count($leads) : 0;
                $totalImported += $count;

                $this->info("Account [{$account->branchName}]: processed {$count} lead(s).");
                $log->info('Account processed', [
                    'branchName' => $account->branchName,
                    'leads' => $count,
                    'createdFrom' => $createdFrom,
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

        $this->info('Bayut lead import complete.');
        $log->info('Lead import complete', [
            'accounts' => $accounts->count(),
            'totalLeads' => $totalImported,
            'createdFrom' => $createdFrom,
        ]);

        return self::SUCCESS;
    }
}
