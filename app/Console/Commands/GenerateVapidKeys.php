<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid-keys';

    protected $description = 'Generate a VAPID keypair for self-hosted browser push notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->newLine();
        $this->info('Add these to your .env — the public key is also served to the frontend by GET /push-subscriptions/public-key.');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('Rotating these invalidates every existing push_subscriptions row — browsers must re-subscribe.');

        return self::SUCCESS;
    }
}
