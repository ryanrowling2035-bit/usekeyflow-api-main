<?php

namespace App\Services;

use App\Constants\Constants;
use App\Exceptions\UserFacingException;
use App\Mail\DynamicMail;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\GlobalSetting;
use App\Models\ManageEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    private array $templateCache = [];

    public function sendEmail(
        string $slug,
        string $toEmail,
        array $data = [],
        array $attachments = [],
        array $textReplacements = [],
        ?string $fromEmail = null,
        ?string $fromName = null
    ): bool {
        $template = $this->getTemplate($slug);

        if (! $template) {
            throw new UserFacingException('Email template not found');
        }

        // Do not send if the template has been deactivated. Compare
        // case-insensitively so both 'active' and 'Active' are treated as live.
        if (strtolower((string) $template->status) !== 'active') {
            Log::info("MailService : sendEmail : skipped, template '{$slug}' is inactive");

            return false;
        }

        // Respect the Settings → Email Automations pause toggles.
        if (! emailAutomationEnabled($slug)) {
            Log::info("MailService : sendEmail : skipped, automation for '{$slug}' is paused");

            return false;
        }

        // For listing-related notifications only, skip recipients that the admin
        // has blocked via the global `_excludeEmails` setting.
        if ($this->isListingActionSlug($slug) && $this->isExcludedRecipient($toEmail)) {
            Log::info("MailService : sendEmail : skipped, '{$toEmail}' is in excludeEmails for listing action '{$slug}'");

            return false;
        }

        $body = $template->emailContent;
        $subject = $template->subject;

        foreach ($data as $key => $value) {
            $body = str_replace('{{'.$key.'}}', $value, $body);
            $subject = str_replace('{{'.$key.'}}', $value, $subject);
        }

        // Apply optional text replacements on subject and body
        foreach ($textReplacements as $search => $replace) {
            $subject = str_ireplace($search, $replace, $subject);
            $body = str_ireplace($search, $replace, $body);
        }

        // $body = emailpdf_layout_wrap($body);
        $html = view('EmailTemplate.templateLayout', [
            'title' => $subject,
            'body' => $body,
        ])->render();

        Mail::to($toEmail)->send(
            new DynamicMail($subject, $html, $attachments, $fromEmail, $fromName)
        );

        $this->logContactEmail($toEmail, $body);

        return true;
    }

    /**
     * Log an email to the contact's email history.
     * Looks up contact by email address and creates a ContactNote with type='email'.
     */
    public function logContactEmail(string $toEmail, string $content): void
    {
        try {
            $contact = Contact::where('email', $toEmail)->first();

            if (! $contact) {
                return;
            }

            ContactNote::create([
                'contactId' => $contact->id,
                'content' => $content,
                'type' => 'email',
                'parent' => 0,
                'createdBy' => user()?->id,
            ]);
        } catch (\Exception $e) {
            Log::error('MailService : logContactEmail : '.$e->getMessage());
        }
    }

    private function getTemplate(string $slug): ?ManageEmail
    {
        if (! isset($this->templateCache[$slug])) {
            $this->templateCache[$slug] = ManageEmail::where('slug', $slug)->first();
        }

        return $this->templateCache[$slug];
    }

    /**
     * Whether the given template slug is a listing (property/boost) notification.
     * Derived from Constants::EMAIL_SLUGS so new listing templates are covered
     * automatically.
     */
    private function isListingActionSlug(string $slug): bool
    {
        static $listingSlugs = null;

        if ($listingSlugs === null) {
            $listingSlugs = array_merge(
                array_values(Constants::EMAIL_SLUGS['property']),
                array_values(Constants::EMAIL_SLUGS['boost']),
            );
        }

        return in_array($slug, $listingSlugs, true);
    }

    /**
     * Whether the recipient email appears in the global `_excludeEmails` blocklist.
     */
    private function isExcludedRecipient(string $email): bool
    {
        $raw = GlobalSetting::where('settingKey', '_excludeEmails')->value('settingValue');
        $excluded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($excluded) || empty($excluded)) {
            return false;
        }

        $excluded = array_map(fn ($e) => strtolower(trim((string) $e)), $excluded);

        return in_array(strtolower(trim($email)), $excluded, true);
    }
}
