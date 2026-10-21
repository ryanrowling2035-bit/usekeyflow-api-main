<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Property;
use App\Models\PropertyMeta;
use App\Models\Role;
use App\Models\User;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendListingExpiryReminder extends Command
{
    protected $signature = 'app:send-listing-expiry-reminder';

    protected $description = 'Send admins a single email listing every property whose meta.expireOn falls 15 days from today.';

    public function handle(MailService $mailService): int
    {
        $expiryDate = Carbon::today()->addDays(15)->toDateString();

        $propertyIds = PropertyMeta::where('metaKey', Constants::PROPERTY_META_KEYS['expireOn'])
            ->whereRaw('DATE(metaValue) = ?', [$expiryDate])
            ->pluck('propertyId');

        if ($propertyIds->isEmpty()) {
            $this->info("No listings expiring on {$expiryDate}.");

            return self::SUCCESS;
        }

        $properties = Property::with(['agent:id,name'])
            ->whereIn('id', $propertyIds)
            ->get();

        if ($properties->isEmpty()) {
            $this->info("No listings expiring on {$expiryDate}.");

            return self::SUCCESS;
        }

        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        $expiryDateLabel = Carbon::parse($expiryDate)->format('F d, Y');

        $rows = [];
        $serial = 1;
        foreach ($properties as $property) {
            $link = $frontendUrl.'/dashboard/property/'.$property->id.'/view';
            $rows[] = sprintf(
                '<tr>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%d</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;"><a href="%s">%s</a></td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'</tr>',
                $serial++,
                e($link),
                e($property->referenceNumber ?? '-'),
                e($property->title ?? '-'),
                e($property->listingType ?? '-'),
                e($property->agent?->name ?? '-'),
                e($expiryDateLabel),
            );
        }

        $adminRoleIds = Role::where('name', Constants::SUPER_ADMIN)->pluck('id');

        $admins = User::whereIn('roleId', $adminRoleIds)
            ->where('status', Constants::USER_STATUS['active'])
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        if ($admins->isEmpty()) {
            $this->warn('No active admins to notify.');

            return self::SUCCESS;
        }

        $tableHtml = '<table style="width:100%; border-collapse:collapse; margin:20px 0; font-size:14px;">'
            .'<thead>'
            .'<tr style="background-color:#00002a; color:#ffffff;">'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">S.No</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Reference</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Title</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Listing Type</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Agent</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Expires On</th>'
            .'</tr>'
            .'</thead>'
            .'<tbody>'.implode('', $rows).'</tbody>'
            .'</table>';

        $sentCount = 0;

        foreach ($admins as $admin) {
            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['property']['listing_expiry_reminder'],
                    $admin->email,
                    [
                        'admin_name' => ucwords($admin->name),
                        'expiry_date' => $expiryDateLabel,
                        'property_table_rows' => $tableHtml,
                        'company_name' => getCompanyName(),
                    ]
                );
                $sentCount++;
            } catch (\Exception $e) {
                Log::error('SendListingExpiryReminder : sendEmail : '.$e->getMessage());
            }
        }

        $this->info("Sent listing expiry reminder for {$properties->count()} listings to {$sentCount} admins.");

        return self::SUCCESS;
    }
}
