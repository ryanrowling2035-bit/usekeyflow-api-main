<?php

use App\Constants\Constants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the Upcoming aged-listing email template and retitle the pocket one now
     * that both reminders fire at 2 weeks instead of 60 days.
     *
     * ManageEmailSeeder truncates manage_emails, so it cannot be re-run on a live
     * environment without wiping admin-edited templates — hence this migration.
     */
    public function up(): void
    {
        DB::table('manage_emails')->updateOrInsert(
            ['slug' => Constants::EMAIL_SLUGS['property']['upcoming_listing_aged']],
            [
                'title' => 'Upcoming Listing Older Than 2 Weeks',
                'subject' => 'Upcoming Listing Review Required - {{listing_reference}}',
                'emailContent' => '
                    <p>Hello {{recipient_name}},</p>

                    <p>Upcoming listing review required: <strong>{{listing_reference}}</strong> has been in Upcoming status for {{days_in_status}} days.</p>

                    <p>
                        <strong>Listing Reference:</strong> {{listing_reference}}<br>
                        <strong>Property Title:</strong> {{property_title}}<br>
                        <strong>Agent:</strong> {{agent_name}}<br>
                        <strong>Created Date:</strong> {{created_date}}<br>
                        <strong>Current Status:</strong> {{current_status}}<br>
                        <strong>Recommended Action:</strong> {{recommended_action}}
                    </p>

                    <p>
                        <a href="{{property_link}}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:5px;font-weight:bold;">
                            View Listing
                        </a>
                    </p>

                    <p>Thank you,<br><strong>{{company_name}}</strong></p>
                ',
                'macro' => '{{recipient_name}}, {{listing_reference}}, {{property_title}}, {{agent_name}}, {{created_date}}, {{current_status}}, {{days_in_status}}, {{recommended_action}}, {{property_link}}, {{company_name}}',
                'status' => Constants::USER_STATUS_ACTIVE,
                'createdBy' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Only touch the title if it is still the untouched 60-day wording, so an
        // admin who has already renamed the template keeps their version.
        DB::table('manage_emails')
            ->where('slug', Constants::EMAIL_SLUGS['property']['pocket_listing_aged'])
            ->where('title', 'Pocket Listing Older Than 60 Days')
            ->update([
                'title' => 'Pocket Listing Older Than 2 Weeks',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('manage_emails')
            ->where('slug', Constants::EMAIL_SLUGS['property']['upcoming_listing_aged'])
            ->delete();

        DB::table('manage_emails')
            ->where('slug', Constants::EMAIL_SLUGS['property']['pocket_listing_aged'])
            ->where('title', 'Pocket Listing Older Than 2 Weeks')
            ->update([
                'title' => 'Pocket Listing Older Than 60 Days',
                'updated_at' => now(),
            ]);
    }
};
