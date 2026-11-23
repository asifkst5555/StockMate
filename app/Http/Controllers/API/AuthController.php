<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\RegisterRequest;
use App\Models\Language;
use App\Models\MultiTenant;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SadminSetting;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\AdminRepository;
use App\Repositories\SubscriptionRepository;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

class AuthController extends AppBaseController
{
    /**
     * The authentication factory implementation.
     *
     * @var \Illuminate\Contracts\Auth\Factory
     */
    protected $auth;

    /**
     * The number of minutes tokens should be allowed to remain valid.
     *
     * @var int
     */
    protected $expiration;

    /**
     * The provider name.
     *
     * @var string
     */
    protected $provider;

    /**
     * Create a new guard instance.
     *
     * @return void
     */
    public function __construct(AuthFactory $auth, int $expiration = null, string $provider = null)
    {
        $this->auth = $auth;
        $this->expiration = config('sanctum.expiration');
        $this->provider = $provider;
    }

    /**
     * @return mixed
     */
    public function login(Request $request)
    {
        app()->setLocale($request->language_code ?? 'en');
        $email = $request->get('email');
        $password = $request->get('password');

        if (empty($email) or empty($password)) {
            return $this->sendError('username and password required', 422);
        }
        $user = User::whereRaw('lower(email) = ?', [strtolower($email)])->first();
        
        if (empty($user)) {
            return $this->sendError(__('messages.error.invalid_username_password'), 422);
        }
        
        $user->language_id = Language::where('iso_code', $user->language)->value('id');
        
        if (! Hash::check($password, $user->password)) {
            return $this->sendError(__('messages.error.invalid_username_password'), 422);
        }

        if ($user->email_verified_at == null) {
            return $this->sendError(__('messages.error.email_not_verified'), 422);
        }

        if (!$user->status) {
            return $this->sendError(__('messages.error.status_inactive'), 422);
        }

        $userPermissions = $user->getAllPermissions()->pluck('name')->toArray();
        $roleName = $user->roles->first()?->name;
        unset($user->roles);
        unset($user->permissions);
        Session::put('auth', $user->id);
        $token = $user->createToken('token')->plainTextToken;
        $user->last_name = $user->last_name ?? '';

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $user,
                'roles' => $roleName,
                'expires_at' => config('sanctum.expiration'),
                'permissions' => $userPermissions,
            ],
            'message' => 'Logged in successfully.',
        ]);
    }

    /**
     * One-click Store Admin demo login.
     * Strictly gated by DEMO_QUICK_LOGIN_ENABLED=true, ignores any client-supplied account
     * parameters, validates Store Admin role only, and explicitly rejects Super Admin accounts.
     */
    public function demoStoreAdminLogin(Request $request): JsonResponse
    {
        if (! config('app.demo_quick_login_enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Demo quick login is not enabled.',
            ], 404);
        }

        $targetEmail = trim((string) config('app.demo_store_admin_email', ''));
        if ($targetEmail === '') {
            return $this->sendError('Demo login is currently unavailable.', 503);
        }

        $user = User::withoutGlobalScopes()
            ->whereRaw('lower(email) = ?', [strtolower($targetEmail)])
            ->first();

        if (! $user || ! $user->status || $user->email_verified_at === null) {
            return $this->sendError('Demo login is currently unavailable.', 422);
        }

        // Enforce Store Admin role strictly and explicitly reject Super Admin accounts.
        if ($user->hasRole(Role::SUPER_ADMIN) || ! $user->hasRole(Role::ADMIN)) {
            Log::warning('Demo quick login rejected due to invalid target role.', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Demo login is not permitted for this account.',
            ], 403);
        }

        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        Session::put('auth', $user->id);

        $user->language_id = Language::where('iso_code', $user->language)->value('id');
        $userPermissions = $user->getAllPermissions()->pluck('name')->toArray();
        $roleName = $user->roles->first()?->name;
        unset($user->roles);
        unset($user->permissions);

        $token = $user->createToken('token')->plainTextToken;
        $user->last_name = $user->last_name ?? '';

        Log::info('Demo Store Admin quick login authenticated.', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $user,
                'roles' => $roleName,
                'expires_at' => config('sanctum.expiration'),
                'permissions' => $userPermissions,
            ],
            'message' => 'Logged in successfully.',
        ]);
    }

    /**
     * Strict local-only quick login for manual development testing.
     * Only permits the two predefined local accounts; never active outside APP_ENV=local.
     */
    public function quickLogin(Request $request): JsonResponse
    {
        if (! app()->environment('local')) {
            return response()->json(['success' => false, 'message' => 'Not Found.'], 404);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:superadmin,admin'],
        ]);

        $allowedAccounts = [
            'superadmin' => 'superadmin@stockmate.com',
            'admin' => 'admin@stockmate.com',
        ];

        $targetEmail = $allowedAccounts[$validated['role']];
        $user = User::withoutGlobalScopes()->whereRaw('lower(email) = ?', [strtolower($targetEmail)])->first();

        if (! $user || ! $user->status || $user->email_verified_at === null) {
            return $this->sendError('Predefined local account is unavailable.', 422);
        }

        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        Session::put('auth', $user->id);

        $user->language_id = Language::where('iso_code', $user->language)->value('id');
        $userPermissions = $user->getAllPermissions()->pluck('name')->toArray();
        $roleName = $user->roles->first()?->name;
        unset($user->roles);
        unset($user->permissions);

        $token = $user->createToken('token')->plainTextToken;
        $user->last_name = $user->last_name ?? '';

        Log::info('Local quick login authenticated.', [
            'quick_login_role' => $validated['role'],
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $user,
                'roles' => $roleName,
                'expires_at' => config('sanctum.expiration'),
                'permissions' => $userPermissions,
            ],
            'message' => 'Logged in successfully.',
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        app()->setLocale($request->language_code ?? 'en');
        // Explicitly allowlist registration attributes — ignore any client-supplied role_id, tenant_id, store_id, status, etc.
        $safeInput = $request->only(['full_name', 'email', 'password', 'phone', 'region']);
        $fullName = explode(' ', trim((string) $safeInput['full_name']), 2);
        $userData = [
            'first_name' => $fullName[0],
            'last_name' => $fullName[1] ?? '',
            'email' => $safeInput['email'],
            'phone' => $safeInput['phone'] ?? null,
            'region' => $safeInput['region'] ?? null,
            'password' => Hash::make($safeInput['password']),
            'status' => 1,
        ];

        try {
            DB::beginTransaction();

            $user = User::create($userData);
            // Server-controlled role assignment: public self-registration ALWAYS receives Store Admin only
            $user->assignRole(Role::ADMIN);

            $store = Store::create([
                'name' => 'My Store',
                'user_id' => $user->id,
            ]);
            $tenant = MultiTenant::create(['store_id' => $store->id]);
            $store->update(['tenant_id' => $tenant->id]);
            $user->update(['tenant_id' => $tenant->id]);

            $plan = Plan::where('assign_while_register', true)->first();

            if ($plan) {
                SubscriptionRepository::createSubscription([
                    'plan_id' => $plan->id,
                    'user_id' => $user->id,
                    'payment_type' => Subscription::TYPE_FREE,
                ]);
            }

            $sadminSettingRow = SadminSetting::where('key', 'send_registration_mail')->first();
            $sadminSetting = $sadminSettingRow ? filter_var($sadminSettingRow->value, FILTER_VALIDATE_BOOLEAN) : true;
            if ($sadminSetting) {
                $user->sendEmailVerificationNotification();
            } else {
                $user->email_verified_at = now();
                $user->save();
            }

            App::make(AdminRepository::class)->defaultSettings($tenant->id);

            $success['token'] = $user->createToken('token')->plainTextToken;
            $success['name'] = $user->name;

            DB::commit();
            return $this->sendResponse($success, 'User registered successfully');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->sendError($e->getMessage());
        }
    }

    public function logout(Request $request): JsonResponse
    {
        if ($bearerToken = $request->bearerToken()) {
            $tokenModel = Sanctum::$personalAccessTokenModel;
            $accessToken = $tokenModel::findToken($bearerToken);
            if ($accessToken) {
                $accessToken->delete();
            }
        }

        $currentToken = Auth::user()?->currentAccessToken();
        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }

        Auth::guard('web')->logout();
        Session::forget('auth');

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            Session::flush();
        }

        return $this->sendSuccess('Logout Successfully');
    }

    public function sendPasswordResetLinkEmail(Request $request): JsonResponse
    {
        app()->setLocale($request->language_code ?? 'en');

        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );
        $user = User::whereEmail($request->email)->first();
        if (! $user) {
            return $this->sendError(__('messages.error.user_not_found'));
        }

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => __($status)], 200);
        } else {
            throw ValidationException::withMessages([
                'email' => __('messages.error.wait_try_again'),
            ]);
        }
    }

    public function emailVerification(Request $request)
    {
        // Check if the URL signature is valid and not expired
        if (! URL::hasValidSignature($request)) {
            return redirect()->route('email-verify')
                ->with('success', false)
                ->with('message', __('messages.error.email_verify_link_expired'));
        }

        $user = User::findOrFail($request->id);

        // Verify the hash matches the user's email
        if (! hash_equals(sha1($user->email), $request->hash)) {
            return redirect()->route('email-verify')
                ->with('success', false)
                ->with('message', __('messages.error.email_verify_link_invalid'));
        }

        // If already verified, inform the user
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('email-verify')
                ->with('success', false)
                ->with('message', __('messages.error.email_already_verified'));
        }

        // Mark the email as verified
        $user->markEmailAsVerified();

        return redirect()->route('email-verify')
            ->with('success', true)
            ->with('message', __('messages.success.email_verified_successfully'));
    }

    public function viewEmailVerification(Request $request)
    {
        $message = session('message', '');
        $success = session('success', false);

        if (empty($message)) {
            return redirect()->route('app');
        }

        return view('web.email-verify', compact('success', 'message'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        app()->setLocale($request->language_code ?? 'en');

        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status == Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 200);
        } else {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }
    }

    public function isValidToken(Request $request): JsonResponse
    {
        if ($token = $request->bearerToken()) {
            $model = Sanctum::$personalAccessTokenModel;

            $accessToken = $model::findToken($token);
            $valid = $this->isValidAccessToken($accessToken);

            return response()->json(['success' => $valid], 200);
        }

        return response()->json(['success' => false], 401);
    }

    /**
     * Determine if the provided access token is valid.
     *
     * @param  mixed  $accessToken
     */
    protected function isValidAccessToken($accessToken): bool
    {
        if (! $accessToken) {
            return false;
        }

        $isValid =
            (! $this->expiration || $accessToken->created_at->gt(now()->subMinutes($this->expiration)))
            && $this->hasValidProvider($accessToken->tokenable);

        if (is_callable(Sanctum::$accessTokenAuthenticationCallback)) {
            $isValid = (bool) (Sanctum::$accessTokenAuthenticationCallback)($accessToken, $isValid);
        }

        return $isValid;
    }

    /**
     * Determine if the tokenable model matches the provider's model type.
     */
    protected function hasValidProvider(Model $tokenable): bool
    {
        if (is_null($this->provider)) {
            return true;
        }

        $model = config("auth.providers.{$this->provider}.model");

        return $tokenable instanceof $model;
    }
}
