<?php

namespace App\Jobs;

use App\Console\Commands\UpdateEnquiryOpenGameStatus;
use App\Constants\Constants;
use App\Models\Enquiry;
use App\Models\User;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyOpenGameReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    // Days of inactivity before an enquiry moves to Open Game — mirrors UpdateEnquiryOpenGameStatus.
    private const THRESHOLDS = [
        'rent' => [Constants::ENQUIRY_PROPERTY_SEARCH => 7, Constants::ENQUIRY_THINKING_ABOUT_IT => 14],
        'sale' => [Constants::ENQUIRY_PROPERTY_SEARCH => 14, Constants::ENQUIRY_THINKING_ABOUT_IT => 30],
    ];

    private const STATUS_LABELS = [
        Constants::ENQUIRY_PROPERTY_SEARCH => 'Property Search',
        Constants::ENQUIRY_THINKING_ABOUT_IT => 'Thinking About It',
    ];

    public function __construct(public int $agentId) {}

    public function handle(MailService $mailService): void
    {
        $agent = User::where('id', $this->agentId)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->whereNotNull('email')
            ->first(['id', 'name', 'email']);

        if (! $agent) {
            return;
        }

        $enquiries = Enquiry::with(['contact:id,name', 'tasks:id,moduleId,module,created_at'])
            ->where('isOpenGame', 0)
            ->whereIn('status', [Constants::ENQUIRY_PROPERTY_SEARCH, Constants::ENQUIRY_THINKING_ABOUT_IT])
            // Sellers and landlords never enter Open Game, so don't remind agents
            // about them approaching it. Mirrors UpdateEnquiryOpenGameStatus.
            ->where(function ($typeQuery) {
                $typeQuery->whereNull('enquiryType')
                    ->orWhereNotIn('enquiryType', UpdateEnquiryOpenGameStatus::OPEN_GAME_EXCLUDED_TYPES);
            })
            ->where('agentId', $this->agentId)
            ->get();

        if ($enquiries->isEmpty()) {
            return;
        }

        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        $now = Carbon::now();
        $rows = [];
        $serial = 1;

        foreach ($enquiries as $enquiry) {
            $type = $enquiry->listingType === 'rent' ? 'rent' : 'sale';
            $threshold = self::THRESHOLDS[$type][$enquiry->status] ?? 14;

            $lastTaskAt = $enquiry->tasks?->max('created_at');
            $lastActivity = $lastTaskAt
                ? Carbon::parse($lastTaskAt)->max(Carbon::parse($enquiry->created_at))
                : Carbon::parse($enquiry->created_at);

            $deadline = $lastActivity->copy()->addDays($threshold);
            $daysSince = (int) $lastActivity->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());

            // Targeted 24-hour warning: only alert the agent on the day BEFORE
            // the enquiry crosses into Open Game — Property Search day 6 of 7,
            // Thinking About It day 29 of 30 (sales) — not a daily digest of
            // every at-risk enquiry. The Open Game engine (UpdateEnquiryOpenGameStatus)
            // flips the enquiry once $daysSince reaches $threshold, so warning at
            // $threshold - 1 gives the agent ~24 hours' notice.
            if ($daysSince !== $threshold - 1) {
                continue;
            }

            $rows[] = sprintf(
                '<tr>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%d</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;"><a href="%s">%s</a></td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%d</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'</tr>',
                $serial++,
                e($frontendUrl.'/dashboard/enquiries/'.$enquiry->id.'/view'),
                e($enquiry->referenceNo ?? $enquiry->id),
                e($enquiry->contact?->masterOrSelf()->name ?? '-'),
                e(self::STATUS_LABELS[$enquiry->status] ?? $enquiry->status),
                e($lastActivity->format('M d, Y')),
                $daysSince,
                e($deadline->format('M d, Y')),
            );
        }

        // No enquiry is exactly 24 hours from Open Game today — nothing to warn about.
        if (empty($rows)) {
            return;
        }

        $tableHtml = '<table style="width:100%; border-collapse:collapse; margin:20px 0; font-size:14px;">'
            .'<thead><tr style="background-color:#00002a; color:#ffffff;">'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">S.No</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Reference</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Client</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Status</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Last Activity</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Days Since</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Open Game Deadline</th>'
            .'</tr></thead>'
            .'<tbody>'.implode('', $rows).'</tbody></table>';

        try {
            $mailService->sendEmail(
                Constants::EMAIL_SLUGS['enquiry']['daily_open_game_reminder'],
                $agent->email,
                [
                    'agent_name' => ucwords($agent->name),
                    'enquiry_count' => count($rows),
                    'enquiry_table' => $tableHtml,
                    'company_name' => getCompanyName(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('NotifyOpenGameReminderJob : sendEmail : '.$e->getMessage());
        }
    }
}
