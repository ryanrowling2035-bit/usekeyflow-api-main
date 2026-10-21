<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a WhatsApp template via the 360degreeapps gateway in the background,
 * so the web request never waits on the external API.
 */
class SendWhatsAppTemplateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(
        public string $to,
        public string $templateName,
        public array $components = [],
        public ?string $language = null,
    ) {}

    public function handle(WhatsAppService $whatsAppService): void
    {
        $whatsAppService->sendTemplate($this->to, $this->templateName, $this->components, $this->language);
    }
}
