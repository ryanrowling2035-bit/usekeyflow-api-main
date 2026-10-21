<?php

namespace Database\Seeders;

use App\Constants\Constants;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GlobalSettingSeeder extends Seeder
{
    public function run()
    {
        DB::table('global_settings')->truncate();

        $settings = [
            [
                'settingKey' => '_smtpHost',
                'settingValue' => 'mail.exclusive-links.com',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_smtpPort',
                'settingValue' => '465',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_smtpUsername',
                'settingValue' => 'crm@exclusive-links.com',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_smtpPassword',
                'settingValue' => 'UN*=lmGL?)%hfF~v',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_smtpEncryption',
                'settingValue' => 'tls',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_sendFromEmail',
                'settingValue' => 'crm@exclusive-links.com',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_sendFromName',
                'settingValue' => 'Exclusive Links',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_websiteTitle',
                'settingValue' => 'Exclusive Links',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_appLogo',
                'settingValue' => null,
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_watermark',
                'settingValue' => null,
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_timeZone',
                'settingValue' => 'Asia/Kolkata',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_matchingRentalPriceVariance',
                'settingValue' => '10',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_matchingSalesPriceVarianceUpTo',
                'settingValue' => '10',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_matchingSalesPriceVarianceAbove',
                'settingValue' => '20',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_matchingSalesPriceThreshold',
                'settingValue' => '3000000',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_twitterLink',
                'settingValue' => 'https://x.com/ELREDubai',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_facebookLink',
                'settingValue' => 'https://www.facebook.com/ELREDubai',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_instagramLink',
                'settingValue' => 'https://www.instagram.com/exclusivelinks/',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_youtubeLink',
                'settingValue' => 'https://www.youtube.com/user/ELREDubai',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_permitRequestEmail',
                'settingValue' => '["226"]',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_permitRequestCustomEmails',
                'settingValue' => '[]',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_excludeEmails',
                'settingValue' => '[]',
                'createdBy' => 1,
            ],
            [
                'settingKey' => '_leadScore',
                'settingValue' => json_encode(Constants::ENQUIRY_LEAD_SCORE),
                'createdBy' => 1,
            ],
        ];
        foreach ($settings as $setting) {
            DB::table('global_settings')->insert([
                'settingKey' => $setting['settingKey'],
                'settingValue' => $setting['settingValue'],
                'createdBy' => $setting['createdBy'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
