<?php

namespace Database\Seeders;

use App\Models\BaseUnit;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\MailTemplate;
use App\Models\Role;
use App\Models\SadminSetting;
use App\Models\Setting;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DefaultSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = User::whereHas('roles', function ($query) {
            $query->where('name', Role::ADMIN);
        })->first()->tenant_id;

        // Base Units (Idempotent)
        $baseUnits = ['piece', 'meter', 'kilogram'];
        foreach ($baseUnits as $baseUnit) {
            BaseUnit::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'name' => $baseUnit,
                ],
                [
                    'is_default' => true,
                ]
            );
        }

        // Mail Templates (Idempotent)
        $mailTemplates = [
            [
                'tenant_id' => $tenantId,
                'template_name' => 'GREETING TO CUSTOMER ON SALES !',
                'content' => '<p>Hi, {customer_name}</p><p>Your sales Id is {sales_id}</p><p>Sales Date: {sales_date}</p><p>Total Amount: {sales_amount}</p><p>You have paid: {paid_amount}</p><p>Due amount: {due_amount}</p><p>Regards,  {app_name}</p>',
                'type' => MailTemplate::MAIL_TYPE_SALE
            ],
            [
                'tenant_id' => $tenantId,
                'template_name' => 'GREETING TO CUSTOMER ON SALES RETURN !',
                'content' => '<p>Hi, {customer_name}</p><p>Your sales return Id is {sales_return_id}</p><p>Sales return Date: {sales_return_date}</p><p>Total Amount: {sales_return_amount}</p><p>Regards,  {app_name}</p>',
                'type' => MailTemplate::MAIL_TYPE_SALE_RETURN,
            ]
        ];
        foreach ($mailTemplates as $mailTemplate) {
            MailTemplate::firstOrCreate(
                ['tenant_id' => $tenantId, 'type' => $mailTemplate['type']],
                $mailTemplate
            );
        }

        // SMS Settings (Idempotent)
        $smsSettings = [
            'url' => 'http://test.com/api/test.php',
            'mobile_key' => '',
            'message_key' => '',
            'payload' => '',
        ];
        foreach ($smsSettings as $key => $value) {
            SmsSetting::firstOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => $value]
            );
        }

        // SMS Templates (Idempotent)
        $smsTemplates = [
            [
                'tenant_id' => $tenantId,
                'template_name' => 'GREETING TO CUSTOMER ON SALES !',
                'content' => 'Hi {customer_name}, Your sales Id is {sales_id}, Sales Date {sales_date}, Total Amount {sales_amount}, You have paid {paid_amount}, and customer total due amount is {due_amount} Thank you visit again',
                'type' => SmsTemplate::SMS_TYPE_SALE,
            ],
            [
                'tenant_id' => $tenantId,
                'template_name' => 'GREETING TO CUSTOMER ON SALES RETURN !',
                'content' => 'Hi {customer_name}, Your sales return Id is {sales_return_id}, Sales return Date {sales_return_date}, and Total Amount is {sales_return_amount} Thank you visit again',
                'type' => SmsTemplate::SMS_TYPE_SALE_RETURN,
            ]
        ];
        foreach ($smsTemplates as $smsTemplate) {
            SmsTemplate::firstOrCreate(
                ['tenant_id' => $tenantId, 'type' => $smsTemplate['type']],
                $smsTemplate
            );
        }

        // Customer (Idempotent — never overwrites existing customer data)
        Customer::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'walk-in-customer'],
            [
                'email' => 'customer@infypos.com',
                'phone' => '123456789',
                'country' => 'Bangladesh',
                'city' => 'Kushtia',
                'address' => 'Mirpur, Kushtia, Bangladesh - 7030',
            ]
        );
        // Warehouse (Idempotent — never overwrites existing warehouse data)
        Warehouse::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'warehouse'],
            [
                'phone' => '01749-560393',
                'country' => 'Bangladesh',
                'city' => 'Kushtia',
                'email' => 'dynamic.digital.ltd@gmail.com',
                'zip_code' => '7030',
            ]
        );

        $usdCurrency = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'United States', 'symbol' => '$']
        );
        Currency::firstOrCreate(
            ['code' => 'BDT'],
            ['name' => 'Bangladesh', 'symbol' => '৳']
        );

        $settings = [
            'currency' => (string) $usdCurrency->id,
            'is_currency_right' => 0,
            'default_customer' => 1,
            'default_warehouse' => 1,
            'date_format' => 'y-m-d',
            'country' => 'Bangladesh',
            'state' => 'Khulna',
            'city' => 'Kushtia',
            // Prefixes
            'purchase_code' => 'PU',
            'purchase_return_code' => 'PR',
            'sale_code' => 'SA',
            'sale_return_code' => 'SR',
            'expense_code' => 'EX',
            // Receipt Settings
            'show_note' => '1',
            'show_phone' => '1',
            'show_customer' => '1',
            'show_address' => '1',
            'show_email' => '1',
            'show_tax_discount_shipping' => '1',
            'show_barcode_in_receipt' => '1',
            'show_logo_in_receipt' => '1',
            'show_product_code' => '1',
            'notes' => 'Thanks for order',
            // use ??
            'show_warehouse' => '1',
            'stripe_key' => 'pu_test_yBzA1qI1PcfRBAVn1vJG2VuS00HcyhQX9LASERTFDDS',
            'stripe_secret' => 'pu_test_yBzA1qI1PcfRBAVn1vJG2VuS00HcyhQX9LASERTFDDS',
            'sms_gateway' => '1',
            'twillo_sid' => 'asd',
            'twillo_token' => 'asd',
            'twillo_from' => 'asd',
            'smtp_host' => 'mailtrap.io',
            'smtp_port' => '2525',
            'smtp_username' => 'test',
            'smtp_password' => 'test',
            'smtp_Encryption' => 'tls',
        ];
        foreach ($settings as $key => $value) {
            Setting::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'key' => $key,
                ],
                [
                    'value' => $value,
                ]
            );
        }

        $sadminSetting = [
            'email' => 'dynamic.digital.ltd@gmail.com',
            'logo' => 'images/logo/stockmate-logo.webp',
            'company_name' => 'StockMate',
            'app_name' => 'StockMate',
            'phone' => '01749-560393',
            'phone_secondary' => '01931-336664',
            'footer' => 'All rights reserved by StockMate',
            'country' => 'Bangladesh',
            'state' => 'Khulna',
            'city' => 'Kushtia',
            'postcode' => '7030',
            'address' => 'Mirpur, Kushtia, Bangladesh - 7030',
            'show_version_on_footer' => '1',
            'show_app_name_in_sidebar' => '1',
            // Default mail settings
            'mail_mailer' => 'smtp',
            'mail_host' => 'mailtrap.io',
            'mail_port' => '2525',
            'sender_name' => 'StockMate',
            'mail_username' => 'test',
            'mail_password' => 'test',
            'mail_from_address' => 'dynamic.digital.ltd@gmail.com',
            'mail_encryption' => 'tls',
            'manual_payment_enabled' => '1',
            'manual_payment_guide' => 'We will approve your request within 24 hours.',
            'term_and_condition' => 'Terms and condition',
            'privacy_policy' => 'Privacy Policy',
            'refund_policy' => 'Refund Policy',
            'admin_default_currency_symbol' => $usdCurrency->symbol,
            'admin_default_currency' => (string) $usdCurrency->id,
            'testimonial_main_title' => 'Customers Who Choose StockMate',
            'hero_button_title' => 'Launch Your Store Today',
        ];
        foreach ($sadminSetting as $key => $value) {
            SadminSetting::firstOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }
}
