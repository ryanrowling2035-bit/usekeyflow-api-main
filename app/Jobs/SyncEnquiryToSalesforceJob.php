<?php

namespace App\Jobs;

use App\Services\SalesforceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncEnquiryToSalesforceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * @param  int  $enquiryId  Local enquiry id, used for history/logging context.
     * @param  array  $enquiryData  Payload consumed by SalesforceService::createEnquiry.
     */
    public function __construct(
        public int $enquiryId,
        public array $enquiryData
    ) {}

    public function handle(SalesforceService $salesforceService): void
    {
        $response = $salesforceService->createEnquiry($this->enquiryData);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncEnquiryToSalesforceJob : failed', [
            'enquiryId' => $this->enquiryId,
            'payload' => $this->enquiryData,
            'error' => $exception->getMessage(),
        ]);
    }
}
