<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Property;
use App\Models\User;
use App\Repositories\DailyMatchingLogRepository;
use App\Repositories\EnquiryRepository;
use App\Services\MailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailyListingMatching extends Command
{
    protected $signature = 'app:send-daily-listing-matching';

    protected $description = 'Send each agent a daily email listing matches between their Published / Pocket listings and all active enquiries.';

    private const ACTIVE_LISTING_STATUSES = [
        Constants::PROPERTY_STATUS_PUBLISHED,
        Constants::PROPERTY_STATUS_POCKET_LISTING,
    ];

    public function handle(EnquiryRepository $enquiryRepository, MailService $mailService, DailyMatchingLogRepository $dailyMatchingLogRepo): int
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        $agentIds = Property::whereIn('status', self::ACTIVE_LISTING_STATUSES)
            ->whereNotNull('agentId')
            ->distinct()
            ->pluck('agentId');

        $agents = User::whereIn('id', $agentIds)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        $sentCount = 0;

        foreach ($agents as $agent) {
            $properties = Property::where('agentId', $agent->id)
                ->whereIn('status', self::ACTIVE_LISTING_STATUSES)
                ->with([
                    'agent:id,name',
                    'physicalOwner:id,name',
                    'subCommunity:propertySubCommunityId,name',
                ])
                ->get();

            $rows = [];
            $serial = 1;
            $toMark = [];

            foreach ($properties as $property) {
                $matches = $enquiryRepository->matchActiveEnquiriesForProperty($property)
                    ->with(['contact:id,name', 'agent:id,name'])
                    ->get();

                $listingPrice = match ($property->listingType) {
                    'rent' => $property->annualRent,
                    'sale' => $property->salePrice,
                    default => $property->price,
                };
                $listingPriceLabel = $listingPrice !== null && $listingPrice !== ''
                    ? 'AED '.number_format((float) $listingPrice)
                    : '-';

                $listingLink = $frontendUrl.'/dashboard/property/'.$property->id.'/view';

                foreach ($matches as $enquiry) {
                    if ($dailyMatchingLogRepo->hasBeenSentListing($agent->id, $property->id, $enquiry->id)) {
                        continue;
                    }

                    $enquiryName = $enquiry->contact?->masterOrSelf()->name ?? $enquiry->enquiryType ?? "Enquiry #{$enquiry->id}";

                    $isOpenGame = (bool) ($enquiry->isOpenGame ?? false);
                    $openGameLabel = $isOpenGame
                        ? '<span style="display:inline-block; padding:2px 8px; border-radius:10px; background-color:#fee2e2; color:#b91c1c; font-weight:600; font-size:12px;">Open Game</span>'
                        : '-';

                    $rows[] = sprintf(
                        '<tr>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%d</td>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;"><a href="%s">%s</a></td>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                        .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                        .'</tr>',
                        $serial++,
                        e($listingLink),
                        e($property->referenceNumber ?? '-'),
                        e($property->subCommunity?->name ?? '-'),
                        e($listingPriceLabel),
                        e($enquiry->referenceNo ?? '-'),
                        e($enquiryName),
                        $openGameLabel,
                        e($enquiry->agent?->name ?? '-'),
                        e($property->physicalOwner?->masterOrSelf()->name ?? '-'),
                    );

                    $toMark[] = [
                        'agent_id' => $agent->id,
                        'property_id' => $property->id,
                        'enquiry_id' => $enquiry->id,
                    ];
                }
            }

            if (empty($rows)) {
                continue;
            }

            $matchingTable = '<table style="width:100%; border-collapse:collapse; margin:20px 0; font-size:14px;">'
                .'<thead>'
                .'<tr style="background-color:#00002a; color:#ffffff;">'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">SNo</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Listing Ref#</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Sub-Community Name</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Listing Price</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Enquiry Ref#</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Enquiry Name</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Open Game</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Enquiry Owner Name</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Property Owner Name</th>'
                .'</tr>'
                .'</thead>'
                .'<tbody>'.implode("\n", $rows).'</tbody>'
                .'</table>';

            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['enquiry']['daily_listing_matching'],
                    $agent->email,
                    [
                        'agent_name' => ucwords($agent->name),
                        'matching_table' => $matchingTable,
                        'company_name' => getCompanyName(),
                    ]
                );
                $sentCount++;

                foreach ($toMark as $item) {
                    $dailyMatchingLogRepo->recordListingSent($item['agent_id'], $item['property_id'], $item['enquiry_id']);
                }
            } catch (\Exception $e) {
                Log::error('SendDailyListingMatching : sendEmail : '.$e->getMessage());
            }
        }

        $this->info("Sent daily listing matching email to {$sentCount} agents.");

        return self::SUCCESS;
    }
}
