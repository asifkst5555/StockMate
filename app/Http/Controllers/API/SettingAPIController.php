<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Resources\SettingResource;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\State;
use App\Models\Warehouse;
use App\Repositories\SettingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Class SettingAPIController
 */
class SettingAPIController extends AppBaseController
{
    /** @var SettingRepository */
    private $settingRepository;

    public function __construct(SettingRepository $productRepository)
    {
        $this->settingRepository = $productRepository;
    }

    private const TENANT_SECRET_KEYS = [
        'stripe_secret',
        'stripe_webhook_secret',
        'paypal_secret',
        'paypal_webhook_id',
        'razorpay_secret',
        'razorpay_webhook_secret',
        'paystack_secret',
        'twillo_token',
        'smtp_password',
    ];

    public function index(): JsonResponse
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();
        // SEC-A3/F-02/V-05: Never expose plaintext secret credentials in tenant settings responses
        foreach (self::TENANT_SECRET_KEYS as $secretKey) {
            if (array_key_exists($secretKey, $settings)) {
                $settings[$secretKey . '_configured'] = ! empty($settings[$secretKey]);
                unset($settings[$secretKey]);
            }
        }
        $settings['store_logo'] = getStoreLogo();
        $settings['store_name'] = getActiveStoreName() ? getActiveStoreName() : ($settings['store_name'] ?? null);
        $settings['warehouse_name'] = Warehouse::whereId($settings['default_warehouse'])->first()->name ?? '';
        $settings['customer_name'] = Customer::whereId($settings['default_customer'])->first()->name ?? '';
        $settings['currency_symbol'] = Currency::whereId($settings['currency'])->first()->symbol ?? '';
        $settings['countries'] = Country::all();

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'Setting data retrieved successfully.'
        );
    }

    public function update(Request $request): JsonResponse
    {
        if ($request->hasFile('store_logo')) {
            $request->validate([
                'store_logo' => 'file|image|mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|dimensions:min_width=1,min_height=1|max:2048',
            ]);
        }

        $input = $request->all();
        $settings = $this->settingRepository->updateSettings($input);

        // V-05: Never echo submitted secret values back in HTTP responses
        $safeSettings = is_array($settings) ? $settings : [];
        foreach (self::TENANT_SECRET_KEYS as $secretKey) {
            if (array_key_exists($secretKey, $safeSettings)) {
                $safeSettings[$secretKey . '_configured'] = ! empty($safeSettings[$secretKey]);
                unset($safeSettings[$secretKey]);
            }
        }

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'attributes' => $safeSettings]),
            'Setting data updated successfully'
        );
    }

    public function clearCache(): JsonResponse
    {
        $user = Auth::user();
        if ($user && $user->hasRole(\App\Models\Role::SUPER_ADMIN)) {
            Artisan::call('cache:clear');
        } else {
            // C2-35: Never allow tenant users to flush global cache (protects cross-tenant idempotency & payment caches)
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                \Illuminate\Support\Facades\Cache::forget('settings_' . $tenantId);
            }
        }

        return $this->sendSuccess(__('messages.success.cache_clear_successfully'));
    }

    public function getFrontSettingsValue(): JsonResponse
    {
        $keyName = [
            'currency',
            'is_currency_right',
            'default_customer',
            'default_warehouse',
            'date_format',
            'store_name'
        ];

        if (Auth::guard('sanctum')->user() && !Auth::guard('sanctum')->user()->hasRole('superadmin')) {
            $user = Auth::guard('sanctum')->user();
            $settings = Setting::whereIn('key', $keyName)->where('tenant_id', $user->tenant_id)->pluck('value', 'key')->toArray();
            $settings['warehouse_name'] = Warehouse::whereId($settings['default_warehouse'])->first()->name ?? '';
            $settings['customer_name'] = Customer::whereId($settings['default_customer'])->first()->name ?? '';
            $settings['currency_symbol'] = Currency::whereId($settings['currency'])->first()->symbol ?? '';
            $settings['store_name'] = getActiveStoreName() ? getActiveStoreName() : ($settings['store_name'] ?? null);
        }
        if (Auth::guard('sanctum')->user() && Auth::guard('sanctum')->user()->hasRole('superadmin')) {
            $settings['admin_default_currency_symbol'] = getSadminSettingValue('admin_default_currency_symbol');
        }
        $settings['app_logo'] = getAppLogoUrl();
        $settings['app_favicon'] = getAppFaviconUrl();
        $settings['app_name'] = getAppName();
        $settings['show_app_name_in_sidebar'] = getSadminSettingValue('show_app_name_in_sidebar');
        $settings['show_version_on_footer'] = getSadminSettingValue('show_version_on_footer');
        $settings['default_country_code'] = getSadminSettingValue('default_country_code');
        $settings['footer'] = getSadminSettingValue('footer');
        $settings['store_logo'] = getStoreLogo();


        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'value' => $settings]),
            'Setting value retrieved successfully.'
        );
    }

    public function getFrontCms(): JsonResponse
    {
        $settings['app_logo'] = getAppLogoUrl();
        $settings['app_favicon'] = getAppFaviconUrl();
        $settings['app_name'] = getAppName();
        $settings['show_app_name_in_sidebar'] = getSadminSettingValue('show_app_name_in_sidebar');
        $settings['show_version_on_footer'] = getSadminSettingValue('show_version_on_footer');
        $settings['footer'] = getSadminSettingValue('footer');
        $settings['default_country_code'] = getSadminSettingValue('default_country_code');
        $settings['local_quick_login_enabled'] = app()->environment('local');
        $settings['demo_quick_login_enabled'] = (bool) config('app.demo_quick_login_enabled', false);

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'value' => $settings]),
            'Setting value retrieved successfully.'
        );
    }

    public function updateReceiptSetting(Request $request)
    {
        $settings = $this->settingRepository->updateReceiptSetting($request->all());

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'Setting data updated successfully'
        );
    }

    public function getStates($countryId): JsonResponse
    {
        $states = State::whereCountryId($countryId)->pluck('name');

        return $this->sendResponse(
            new SettingResource(['type' => 'states', 'value' => $states]),
            'States retrieved successfully.'
        );
    }

    public function getPosSettings(): JsonResponse
    {
        $getArray = [
            'enable_pos_click_audio',
            'click_audio',
            'show_pos_stock_product',
        ];

        $settings = Setting::whereIn('key', $getArray)->pluck('value', 'key')->toArray();
        $settings['enable_pos_click_audio'] = $settings['enable_pos_click_audio'] ?? false;
        $settings['show_pos_stock_product'] = $settings['show_pos_stock_product'] ?? false;
        if (!isset($settings['click_audio'])) {
            $settings['click_audio'] = asset('images/click_audio.mp3');
            Setting::updateOrCreate(['key' => 'click_audio'], ['value' => $settings['click_audio']]);
        }

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'POS Setting data retrieved successfully.'
        );
    }

    public function updatePosSettings(Request $request): JsonResponse
    {
        if ($request->hasFile('click_audio')) {
            $request->validate([
                'click_audio' => 'file|mimes:mp3,wav,ogg|mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg|max:2048',
            ]);
        }

        $input = $request->all();
        $this->settingRepository->updatePosSettings($input);
        return $this->sendSuccess(__('messages.success.pos_settings_updated'));
    }

    public function getDualScreenSettings(): JsonResponse
    {
        $getArray = [
            'dual_screen_header_text',
            'dual_screen_images',
        ];

        $settings = Setting::whereIn('key', $getArray)->pluck('value', 'key')->toArray();
        if (isset($settings['dual_screen_images'])) {
            $settings['dual_screen_images'] = json_decode($settings['dual_screen_images'], true);
        } else {
            $settings['dual_screen_images'] = [];
        }
        $settings['dual_screen_header_text'] = $settings['dual_screen_header_text'] ?? null;

        return $this->sendResponse(
            new SettingResource(['type' => 'dual-screen', 'attributes' => $settings]),
            'POS Setting data retrieved successfully.'
        );
    }

    public function updateDualScreenSettings(Request $request): JsonResponse
    {
        $fileRules = [];
        foreach (['image1', 'image2', 'image3', 'image4', 'image5'] as $field) {
            if ($request->hasFile($field)) {
                $fileRules[$field] = 'file|image|mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|dimensions:min_width=1,min_height=1|max:2048';
            }
        }
        if (! empty($fileRules)) {
            $request->validate($fileRules);
        }

        $input = $request->all();
        $this->settingRepository->updateDualScreenSettings($input);
        return $this->sendSuccess(__('messages.success.dual_screen_settings_updated'));
    }
}
