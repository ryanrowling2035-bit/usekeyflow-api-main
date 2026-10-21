<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Jobs\SendWhatsAppTemplateJob;
use App\Models\Closing;
use App\Models\Enquiry;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Time-based WhatsApp follow-ups to the client after an enquiry is created.
 *  - Initial Follow-up : ~25 hours after creation  (script_a_initial_followup_with_details_new)
 *  - Second Follow-up  : ~3 days  after creation   (script_b__second_followup)
 *
 * Runs hourly. A 1-hour window (matching the schedule) prevents back-blasting old
 * enquiries, and enquiry history is used as a dedupe safety net.
 */
class SendWhatsAppFollowups extends Command
{
    protected $signature = 'app:send-whatsapp-followups';

    protected $description = 'Send time-based WhatsApp touchpoints to clients (Initial: 25h, Second: 3 days after creation, Reactivation: 30 days no activity).';

    public const INITIAL_FIELD = 'whatsapp_initial_followup_sent';

    public const SECOND_FIELD = 'whatsapp_second_followup_sent';

    public const REACTIVATION_FIELD = 'whatsapp_reactivation_sent';

    public const CLOSED_FEEDBACK_FIELD = 'whatsapp_closed_feedback_sent';

    public const AFTER_SALES_FIELD = 'whatsapp_after_sales_sent';

    public const LOST_FEEDBACK_FIELD = 'whatsapp_lost_feedback_sent';

    public function handle(): int
    {
        $initial = $this->sendInitialFollowups();
        $second = $this->sendSecondFollowups();
        $reactivation = $this->sendReactivations();
        $closedFeedback = $this->sendClosedFeedback();
        $afterSales = $this->sendAfterSales();
        $lostFeedback = $this->sendLostFeedback();

        $this->info("Initial: {$initial}, Second: {$second}, Reactivation: {$reactivation}, ClosedFeedback: {$closedFeedback}, AfterSales: {$afterSales}, LostFeedback: {$lostFeedback} queued.");

        return self::SUCCESS;
    }

    /**
     * Initial Follow-up — ~25 hours after the enquiry was created.
     */
    private function sendInitialFollowups(): int
    {
        $template = config('services.whatsapp_360.templates.initial_followup');
        $enquiries = $this->dueEnquiries(now()->subHours(26), now()->subHours(25), self::INITIAL_FIELD);
        $count = 0;

        foreach ($enquiries as $enquiry) {
            $phone = $enquiry->contact?->phone;
            if (! $template || ! $phone) {
                continue;
            }

            SendWhatsAppTemplateJob::dispatch($phone, $template, [
                WhatsAppService::textParams([
                    $enquiry->contact?->masterOrSelf()->name ?? 'there',                                  // {{1}} client name
                    $enquiry->bedroomsMin ?? 'N/A',                                       // {{2}} bedrooms
                    $enquiry->enquiryPropertyType ?? $enquiry->enquiryType ?? 'property', // {{3}} property type
                    $enquiry->community ?? 'Dubai',                                       // {{4}} community/location
                    $enquiry->leadSource ?? 'our portal',                                // {{5}} source
                ]),
            ]);

            $this->markSent($enquiry, self::INITIAL_FIELD, 'Initial follow-up (25h) WhatsApp sent');
            $count++;
        }

        return $count;
    }

    /**
     * Second Follow-up — ~3 days after the enquiry was created.
     */
    private function sendSecondFollowups(): int
    {
        $template = config('services.whatsapp_360.templates.second_followup');
        $enquiries = $this->dueEnquiries(now()->subHours(73), now()->subHours(72), self::SECOND_FIELD);
        $count = 0;

        foreach ($enquiries as $enquiry) {
            $phone = $enquiry->contact?->phone;
            if (! $template || ! $phone) {
                continue;
            }

            SendWhatsAppTemplateJob::dispatch($phone, $template, [
                WhatsAppService::textParams([
                    $enquiry->contact?->masterOrSelf()->name ?? 'there', // {{1}} client name
                ]),
            ]);

            $this->markSent($enquiry, self::SECOND_FIELD, 'Second follow-up (3 days) WhatsApp sent');
            $count++;
        }

        return $count;
    }

    /**
     * Reactivation — enquiries with no activity for ~30 days (re-engage the client).
     * "No activity" = the enquiry has not been updated and no task touched in 30 days.
     */
    private function sendReactivations(): int
    {
        $template = config('services.whatsapp_360.templates.reactivation');
        $cutoff = now()->subDays(30);
        // Window so only enquiries that have *just* crossed the 30-day no-activity mark are
        // reactivated — this avoids blasting the entire historical backlog of dormant leads.
        $windowStart = now()->subDays(31);

        $enquiries = Enquiry::with('contact')
            ->whereNotNull('contactId')
            ->whereBetween('updated_at', [$windowStart, $cutoff])
            ->whereNotIn('status', [Constants::ENQUIRY_LOST, Constants::ENQUIRY_SOLD, Constants::ENQUIRY_RENTED])
            ->whereDoesntHave('tasks', fn ($q) => $q->where('updated_at', '>=', $cutoff))
            ->whereDoesntHave('history', fn ($q) => $q->where('field', self::REACTIVATION_FIELD))
            ->get();

        $count = 0;

        foreach ($enquiries as $enquiry) {
            $phone = $enquiry->contact?->phone;
            if (! $template || ! $phone) {
                continue;
            }

            SendWhatsAppTemplateJob::dispatch($phone, $template, [
                WhatsAppService::textParams([
                    $enquiry->bedroomsMin ?? 'N/A',                                       // {{1}} bedrooms
                    $enquiry->enquiryPropertyType ?? $enquiry->enquiryType ?? 'property', // {{2}} property type
                    $enquiry->community ?? 'Dubai',                                       // {{3}} community/location
                    $enquiry->leadSource ?? 'our portal',                                // {{4}} source
                ]),
            ]);

            $this->markSent($enquiry, self::REACTIVATION_FIELD, 'Reactivation (30 days no activity) WhatsApp sent');
            $count++;
        }

        return $count;
    }

    /**
     * Feedback (Closed Lead) — ~3 days after a closing is marked Closed, ask the client
     * for feedback. Naturally windowed by the closing date, so no backlog blast.
     */
    private function sendClosedFeedback(): int
    {
        $template = config('services.whatsapp_360.templates.feedback_closed');

        $closings = Closing::with('enquiry.contact')
            ->where('status', Constants::CLOSE_STATUS_CLOSED)
            ->whereDate('closingDate', now()->subDays(3))
            ->whereHas('enquiry', fn ($q) => $q->whereNotNull('contactId'))
            ->whereDoesntHave('enquiry.history', fn ($q) => $q->where('field', self::CLOSED_FEEDBACK_FIELD))
            ->get();

        $count = 0;

        foreach ($closings as $closing) {
            $contact = $closing->enquiry?->contact;
            $phone = $contact?->phone;
            if (! $template || ! $phone) {
                continue;
            }

            SendWhatsAppTemplateJob::dispatch($phone, $template, [
                WhatsAppService::textParams([$contact?->masterOrSelf()->name ?? 'there']), // {{1}} client name
            ]);

            $this->markSent($closing->enquiry, self::CLOSED_FEEDBACK_FIELD, 'Closed-lead feedback (3 days after closing) WhatsApp sent');
            $count++;
        }

        return $count;
    }

    /**
     * After Sales — ~5 days after a closing is marked Closed, send the client the
     * after-sales WhatsApp (fallback when the AI after-sales call isn't answered).
     * Naturally windowed by the closing date, so no backlog blast.
     */
    private function sendAfterSales(): int
    {
        $template = config('services.whatsapp_360.templates.after_sales');

        $closings = Closing::with('enquiry.contact')
            ->where('status', Constants::CLOSE_STATUS_CLOSED)
            ->whereDate('closingDate', now()->subDays(5))
            ->whereHas('enquiry', fn ($q) => $q->whereNotNull('contactId'))
            ->whereDoesntHave('enquiry.history', fn ($q) => $q->where('field', self::AFTER_SALES_FIELD))
            ->get();

        $count = 0;

        foreach ($closings as $closing) {
            $phone = $closing->enquiry?->contact?->phone;
            if (! $template || ! $phone) {
                continue;
            }

            // script_c_cta2_reponse has no body variables — send template with buttons only.
            SendWhatsAppTemplateJob::dispatch($phone, $template, []);

            $this->markSent($closing->enquiry, self::AFTER_SALES_FIELD, 'After-sales (5 days after closing) WhatsApp sent');
            $count++;
        }

        return $count;
    }

    /**
     * Feedback (Lost Lead) — ~7 days after an enquiry is marked Lost, ask the client
     * for feedback. Uses a window on updated_at (set when the lead was marked Lost) so the
     * historical backlog of lost leads is not blasted.
     */
    private function sendLostFeedback(): int
    {
        $template = config('services.whatsapp_360.templates.feedback_lost');

        $enquiries = Enquiry::with('contact')
            ->whereNotNull('contactId')
            ->where('status', Constants::ENQUIRY_LOST)
            ->whereBetween('updated_at', [now()->subDays(8), now()->subDays(7)])
            ->whereDoesntHave('history', fn ($q) => $q->where('field', self::LOST_FEEDBACK_FIELD))
            ->get();

        $count = 0;

        foreach ($enquiries as $enquiry) {
            $phone = $enquiry->contact?->phone;
            if (! $template || ! $phone) {
                continue;
            }

            SendWhatsAppTemplateJob::dispatch($phone, $template, [
                WhatsAppService::textParams([$enquiry->contact?->masterOrSelf()->name ?? 'there']), // {{1}} client name
            ]);

            $this->markSent($enquiry, self::LOST_FEEDBACK_FIELD, 'Lost-lead feedback (7 days after Lost) WhatsApp sent');
            $count++;
        }

        return $count;
    }

    /**
     * Active enquiries created within the [$from, $to] window that have not yet
     * received this follow-up.
     */
    private function dueEnquiries($from, $to, string $field)
    {
        return Enquiry::with('contact')
            ->whereNotNull('contactId')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', [Constants::ENQUIRY_LOST, Constants::ENQUIRY_SOLD, Constants::ENQUIRY_RENTED])
            ->whereDoesntHave('history', fn ($q) => $q->where('field', $field))
            ->get();
    }

    private function markSent(Enquiry $enquiry, string $field, string $message): void
    {
        logEnquiryHistory([
            'enquiryId' => $enquiry->id,
            'field' => $field,
            'originalValue' => null,
            'newValue' => json_encode(['message' => $message]),
        ]);
    }
}
