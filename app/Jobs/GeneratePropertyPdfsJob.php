<?php

namespace App\Jobs;

use App\Services\PropertyPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePropertyPdfsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(public int $propertyId) {}

    public function handle(PropertyPdfService $propertyPdfService): void
    {
        $propertyPdfService->autoGeneratePdfForWebsite($this->propertyId);
    }
}
