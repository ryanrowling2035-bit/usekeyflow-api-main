<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Enquiry;
use App\Models\User;
use App\Repositories\DailyMatchingLogRepository;
use App\Repositories\PropertyRepository;
use App\Services\MailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailyEnquiryMatching extends Command
{
    protected $signature = 'app:send-daily-enquiry-matching';

    protected $description = 'Send each agent a daily email listing matches between their active enquiries and all Published / Pocket listings.';

    public function handle(PropertyRepository $propertyRepository, MailService $mailService, DailyMatchingLogRepository $dailyMatchingLogRepo): int
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        $agentIds = Enquiry::whereIn('status', Constants::ENQUIRY_ACTIVE_STATUSES)
            ->whereNotNull('agentId')
            ->distinct()
            ->pluck('agentId');

        $agents = User::whereIn('id', $agentIds)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        $sentCount = 0;

        foreach ($agents as $agent) {
            $enquiries = Enquiry::where('agentId', $agent->id)
                ->whereIn('status', Constants::ENQUIRY_ACTIVE_STATUSES)
                ->get();

            $rows = [];
            $serial = 1;
            $toMark = [];

            $enquiries->load('contact:id,name');

            foreach ($enquiries as $enquiry) {
                $matches = $propertyRepository->matchActiveListingsForEnquiry($enquiry)
                    ->with(['agent:id,name', 'physicalOwner:id,name', 'subCommunity:propertySubCommunityId,name'])
                    ->get();

                $enquiryName = $enquiry->contact?->masterOrSelf()->name ?? $enquiry->enquiryType ?? "Enquiry #{$enquiry->id}";

                $isOpenGame = (bool) ($enquiry->isOpenGame ?? false);
                $openGameLabel = $isOpenGame
                    ? '<span style="display:inline-block; padding:2px 8px; border-radius:10px; background-color:#fee2e2; color:#b91c1c; font-weight:600; font-size:12px;">Open Game</span>'
                    : '-';

                foreach ($matches as $listing) {
                    if ($dailyMatchingLogRepo->hasBeenSentEnquiry($agent->id, $enquiry->id, $listing->id)) {
                        continue;
                    }

                    $enquiryLink = $frontendUrl.'/dashboard/enquiries/'.$enquiry->id.'/view';
                    $listingPrice = match ($listing->listingType) {
                        'rent' => $listing->annualRent,
                        'sale' => $listing->salePrice,
                        default => $listing->price,
                    };
                    $listingPriceLabel = $listingPrice !== null && $listingPrice !== ''
                        ? 'AED '.number_format((float) $listingPrice)
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
                        e($enquiryLink),
                        e($enquiry->referenceNo ?? '-'),
                        e($enquiryName),
                        $openGameLabel,
                        e($listing->referenceNumber ?? '-'),
                        e($listing->subCommunity?->name ?? '-'),
                        e($listingPriceLabel),
                        e($listing->agent?->name ?? '-'),
                        e($listing->physicalOwner?->masterOrSelf()->name ?? '-'),
                    );

                    $toMark[] = [
                        'agent_id' => $agent->id,
                        'enquiry_id' => $enquiry->id,
                        'property_id' => $listing->id,
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
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Enquiry Ref#</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Enquiry Name</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Open Game</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Listing Ref#</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Sub-Community Name</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Listing Price</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Listing Owner Name</th>'
                .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Property Owner Name</th>'
                .'</tr>'
                .'</thead>'
                .'<tbody>'.implode("\n", $rows).'</tbody>'
                .'</table>';

            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['enquiry']['daily_matching_4pm'],
                    $agent->email,
                    [
                        'agent_name' => ucwords($agent->name),
                        'matching_table' => $matchingTable,
                        'company_name' => getCompanyName(),
                    ]
                );
                $sentCount++;

                foreach ($toMark as $item) {
                    $dailyMatchingLogRepo->recordEnquirySent($item['agent_id'], $item['enquiry_id'], $item['property_id']);
                }
            } catch (\Exception $e) {
                Log::error('SendDailyEnquiryMatching : sendEmail : '.$e->getMessage());
            }
        }

        $this->info("Sent daily enquiry matching email to {$sentCount} agents.");

        return self::SUCCESS;
    }
}
