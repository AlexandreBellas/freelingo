<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\PresentsUsers;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\AvatarService;
use App\Services\Email\EmailService;
use App\Services\QuotaService;
use App\Services\Redis\ArrayRedis;
use App\Services\Security\Jwt;
use App\Services\Security\PasswordHasher;
use App\Services\UserLanguageService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use PresentsUsers;

    private const PASSWORD_PATTERN = '/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{10,25}$/';

    private const SUPPORTED_LANGUAGES = [
        'en', 'es', 'fr', 'pt', 'de', 'it', 'ru', 'nl', 'pl', 'ro', 'tr', 'sv', 'da', 'fi', 'hr',
    ];

    private const SUPPORTED_TARGET_LANGUAGES = [
        'en-US', 'en-GB', 'de-DE', 'es-ES', 'fr-FR', 'it-IT', 'ja-JP', 'ko-KR', 'pt-PT', 'zh-CN',
    ];

    private const SUPPORTED_UI_LOCALES = [
        'en', 'es', 'fr', 'pt', 'de', 'it', 'pl', 'nl', 'ro', 'ru', 'tr', 'sv', 'da', 'fi', 'hr',
    ];

    private const LEARNING_GOALS = [
        'travel', 'work', 'academic', 'daily', 'media', 'emigration', 'exams', 'social',
    ];

    public function __construct(
        private readonly ArrayRedis $redis,
        private readonly EmailService $email,
        private readonly UserLanguageService $languages,
        private readonly QuotaService $quotas,
        private readonly AvatarService $avatars,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $username = (string) ($data['username'] ?? '');
        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $native = (string) ($data['native_language'] ?? '');
        $target = (string) ($data['target_language'] ?? 'en-GB');
        $display = $data['display_name'] ?? null;
        $invite = $data['invite_token'] ?? null;

        $this->assertUsername($username);
        $this->assertEmail($email);
        $this->assertPassword($password);
        $this->assertNative($native);
        $this->assertTarget($target);
        if ($display !== null && strlen((string) $display) > 100) {
            throw new ApiException('display_name is too long', 422);
        }

        if (! Settings::bool('allow_registration')) {
            if ($invite) {
                $valid = $this->redis->get('invite:'.$invite);
                if (! $valid) {
                    throw new ApiException('Invalid or expired invite', 403);
                }
                $this->redis->delete('invite:'.$invite);
            } else {
                throw new ApiException('Registration is closed', 403);
            }
        }

        $blocked = Settings::get('blocked_email_domains', []);
        if (is_array($blocked) && $blocked !== []) {
            $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
            $blockedLower = array_map('strtolower', $blocked);
            if (in_array($domain, $blockedLower, true)) {
                throw new ApiException('Email domain not allowed', 422);
            }
        }

        if (User::query()->where('username', $username)->exists()) {
            throw new ApiException('Username already taken', 409);
        }
        if (User::query()->where('email', $email)->exists()) {
            throw new ApiException('Email already taken', 409);
        }

        $role = (User::query()->count() === 0 && Settings::bool('first_user_is_admin')) ? 'admin' : 'user';
        $user = User::query()->create([
            'username' => $username,
            'email' => $email,
            'display_name' => $display ?: $username,
            'hashed_password' => PasswordHasher::hash($password),
            'native_language' => $native,
            'target_language' => $target,
            'role' => $role,
            'is_active' => true,
            'is_verified' => ! Settings::bool('email_enabled'),
            'created_at' => Carbon::now('UTC'),
        ]);

        if (Settings::bool('stripe_enabled') && Settings::bool('freemium_trial_enabled')) {
            $user->freemium_trial_ends_at = Carbon::now('UTC')->addDays(Settings::int('freemium_trial_days', 7));
            $user->freemium_trial_used = true;
            $user->save();
        }

        $access = Jwt::createAccessToken($user->id, $user->role);
        $refresh = Jwt::createRefreshToken();
        $ttl = Settings::int('refresh_token_expire_days', 30) * 86400;
        $this->redis->setex('refresh:'.$refresh, $ttl, (string) $user->id);

        if ($user->email && Settings::bool('email_enabled')) {
            $verify = (string) \Illuminate\Support\Str::uuid();
            $this->redis->setex('verify_email:'.$verify, 86400, (string) $user->id);
            $this->email->sendVerificationEmail($user->email, $user->display_name, $verify, $user->native_language);
            $this->email->sendWelcomeEmail($user->email, $user->display_name, $user->native_language);
        }

        return $this->withRefresh(response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'access_token' => $access,
        ]), $refresh, $ttl);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $this->assertEmail($email);
        if ($password === '' || strlen($password) > 25) {
            throw new ApiException('Invalid credentials', 422);
        }

        $user = User::query()->where('email', $email)->first();
        $passwordOk = $user ? PasswordHasher::verify($password, $user->hashed_password) : false;
        if (! $user) {
            PasswordHasher::dummyVerify();
        }
        if (! $passwordOk || ! $user || ! $user->is_active) {
            throw new ApiException('Invalid credentials', 401);
        }

        $user->last_login = Carbon::now('UTC');
        $user->save();

        $access = Jwt::createAccessToken($user->id, $user->role);
        $refresh = Jwt::createRefreshToken();
        $ttl = Settings::int('refresh_token_expire_days', 30) * 86400;
        $this->redis->setex('refresh:'.$refresh, $ttl, (string) $user->id);

        return $this->withRefresh(response()->json([
            'access_token' => $access,
            'token_type' => 'bearer',
        ]), $refresh, $ttl);
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = $request->cookie('refresh_token');
        if (! $token) {
            throw new ApiException('Missing refresh token', 401);
        }
        $userId = $this->redis->get('refresh:'.$token);
        if (! $userId) {
            throw new ApiException('Invalid or expired refresh token', 401);
        }

        $authorization = $request->header('Authorization');
        if ($authorization !== null) {
            $parts = explode(' ', $authorization, 2);
            $scheme = strtolower($parts[0] ?? '');
            $previousToken = $parts[1] ?? '';
            try {
                if ($scheme !== 'bearer' || $previousToken === '') {
                    throw new ApiException('Invalid refresh context', 401);
                }
                $previous = Jwt::decode($previousToken, ['verify_exp' => false]);
                if (! isset($previous['exp'])) {
                    throw new ApiException('Invalid refresh context', 401);
                }
            } catch (ApiException) {
                throw new ApiException('Invalid refresh context', 401);
            }
            if ((string) $previous['sub'] !== (string) $userId) {
                throw new ApiException('auth_context_changed', 409);
            }
        }

        $expected = $request->header('X-Expected-User-ID');
        if ($expected !== null && (string) $expected !== (string) $userId) {
            throw new ApiException('auth_context_changed', 409);
        }

        $this->redis->delete('refresh:'.$token);
        $newRefresh = Jwt::createRefreshToken();
        $ttl = Settings::int('refresh_token_expire_days', 30) * 86400;
        $this->redis->setex('refresh:'.$newRefresh, $ttl, (string) $userId);

        $user = User::query()->find((int) $userId);
        if (! $user) {
            throw new ApiException('User not found', 401);
        }

        return $this->withRefresh(response()->json([
            'access_token' => Jwt::createAccessToken($user->id, $user->role),
            'token_type' => 'bearer',
        ]), $newRefresh, $ttl);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->cookie('refresh_token');
        if ($token) {
            $this->redis->delete('refresh:'.$token);
        }

        return response()->json(['detail' => 'Logged out'])->withoutCookie('refresh_token');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        try {
            if (
                Settings::bool('stripe_enabled')
                && Settings::bool('freemium_trial_enabled')
                && $user->freemium_trial_ends_at === null
                && ! $user->freemium_trial_used
                && ! in_array($user->subscription_status, ['active', 'trialing'], true)
            ) {
                $user->freemium_trial_ends_at = Carbon::now('UTC')->addDays(Settings::int('freemium_trial_days', 7));
                $user->freemium_trial_used = true;
                $user->save();
            }
        } catch (\Throwable) {
        }

        return response()->json($this->userPayload($user->refresh()));
    }

    public function updateMe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        $data = $request->json()->all();

        if (array_key_exists('display_name', $data) && $data['display_name'] !== null) {
            if (strlen((string) $data['display_name']) > 100) {
                throw new ApiException('display_name is too long', 422);
            }
            $user->display_name = $data['display_name'];
        }
        if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== $user->email) {
            $this->assertEmail((string) $data['email']);
            if (User::query()->where('email', $data['email'])->exists()) {
                throw new ApiException('Email already taken', 409);
            }
            $user->email = $data['email'];
        }
        if (array_key_exists('password', $data) && $data['password'] !== null) {
            $this->assertPassword((string) $data['password']);
            $user->hashed_password = PasswordHasher::hash((string) $data['password']);
        }
        if (array_key_exists('native_language', $data) && $data['native_language'] !== null) {
            $this->assertNative((string) $data['native_language']);
            $user->native_language = $data['native_language'];
        }
        if (array_key_exists('target_language', $data) && $data['target_language'] !== null) {
            $this->assertTarget((string) $data['target_language']);
            $user->target_language = $data['target_language'];
            $this->syncTargetLanguage($user, (string) $data['target_language']);
        }
        if (array_key_exists('ui_locale', $data)) {
            $locale = $data['ui_locale'];
            if ($locale === '') {
                $locale = null;
            }
            if ($locale !== null) {
                if (! in_array($locale, self::SUPPORTED_UI_LOCALES, true)) {
                    throw new ApiException('Unsupported UI locale', 422);
                }
                $user->ui_locale = $locale;
            }
        }
        if (array_key_exists('conversation_max_duration', $data) && $data['conversation_max_duration'] !== null) {
            if (! in_array((int) $data['conversation_max_duration'], [900, 1800], true)) {
                throw new ApiException('conversation_max_duration must be 900 or 1800', 422);
            }
            $user->conversation_max_duration = (int) $data['conversation_max_duration'];
        }
        if (array_key_exists('conversation_inactivity_timeout', $data) && $data['conversation_inactivity_timeout'] !== null) {
            if (! in_array((int) $data['conversation_inactivity_timeout'], [60, 180, 300], true)) {
                throw new ApiException('conversation_inactivity_timeout must be 60, 180, or 300', 422);
            }
            $user->conversation_inactivity_timeout = (int) $data['conversation_inactivity_timeout'];
        }
        if (array_key_exists('conversation_speech_pause', $data) && $data['conversation_speech_pause'] !== null) {
            if (! in_array((int) $data['conversation_speech_pause'], [0, 1000, 2000, 3000], true)) {
                throw new ApiException('conversation_speech_pause must be 0, 1000, 2000, or 3000', 422);
            }
            $user->conversation_speech_pause = (int) $data['conversation_speech_pause'];
        }
        if (array_key_exists('bio', $data) && $data['bio'] !== null) {
            $bio = (string) $data['bio'];
            if (strlen($bio) > 500) {
                throw new ApiException('bio is too long', 422);
            }
            $user->bio = trim($bio) === '' ? null : $bio;
        }
        if (array_key_exists('learning_goals', $data) && $data['learning_goals'] !== null) {
            if (! is_array($data['learning_goals'])) {
                throw new ApiException('Invalid learning goals', 422);
            }
            foreach ($data['learning_goals'] as $goal) {
                if (! in_array($goal, self::LEARNING_GOALS, true)) {
                    throw new ApiException('Invalid learning goal: '.$goal, 422);
                }
            }
            $user->learning_goals = array_values($data['learning_goals']);
        }

        $user->save();

        return response()->json($this->userPayload($user->refresh()));
    }

    public function deleteMe(Request $request): Response
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        if ($user->role === 'admin') {
            throw new ApiException('Admin accounts cannot be self-deleted', 403);
        }
        $token = $request->cookie('refresh_token');
        if ($token) {
            $this->redis->delete('refresh:'.$token);
        }
        $email = $user->email;
        $name = $user->display_name;
        $locale = $user->native_language;
        $this->avatars->deleteStoredFile($user);
        $user->delete();
        try {
            $this->email->sendAccountDeletedEmail($email, $name, $locale);
        } catch (\Throwable) {
        }

        return response()->noContent()->withoutCookie('refresh_token');
    }

    public function quota(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        $quota = $this->quotas->status(
            $user->id,
            (int) $user->conversation_weekly_sessions,
            (int) $user->conversation_daily_minutes,
            (int) $user->conversation_weekly_minutes,
        );
        $tokens = $this->quotas->monthlyTokensUsed($user->id);
        $quota['tokens_this_month'] = $tokens;
        $quota['tokens_monthly_limit'] = (int) $user->monthly_tokens_limit;
        $quota['tokens_unlimited'] = (int) $user->monthly_tokens_limit === 0;

        return response()->json($quota);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        $updated = $this->avatars->upload($user, $request->file('file'));

        return response()->json($this->userPayload($updated));
    }

    public function avatarFile(Request $request): Response
    {
        /** @var User $user */
        $user = $request->attributes->get('user');

        return $this->avatars->file($user);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');

        return response()->json($this->userPayload($this->avatars->delete($user)));
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $token = (string) $request->query('token', '');
        $userId = $this->redis->get('verify_email:'.$token);
        if (! $userId) {
            throw new ApiException('Invalid or expired verification token', 400);
        }
        $user = User::query()->find((int) $userId);
        if (! $user) {
            throw new ApiException('User not found', 404);
        }
        $user->is_verified = true;
        $user->save();
        $this->redis->delete('verify_email:'.$token);

        return response()->json(['detail' => 'Email verified successfully']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        if ($user->is_verified) {
            return response()->json(['detail' => 'Already verified']);
        }
        if (! $user->email) {
            throw new ApiException('No email address on file', 400);
        }
        if (! Settings::bool('email_enabled')) {
            throw new ApiException('Email not configured', 503);
        }
        $verify = (string) \Illuminate\Support\Str::uuid();
        $this->redis->setex('verify_email:'.$verify, 86400, (string) $user->id);
        $this->email->sendVerificationEmail($user->email, $user->display_name, $verify, $user->native_language);

        return response()->json(['detail' => 'Verification email sent']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = (string) ($request->json('email') ?? '');
        $this->assertEmail($email);
        $user = User::query()->where('email', $email)->first();
        if ($user && Settings::bool('email_enabled') && $user->email) {
            $reset = (string) \Illuminate\Support\Str::uuid();
            $this->redis->setex('reset_password:'.$reset, 3600, (string) $user->id);
            $this->email->sendResetPasswordEmail($user->email, $user->display_name, $reset, $user->native_language);
        }

        return response()->json(['detail' => 'If that email is registered you will receive a reset link shortly']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $token = (string) ($request->json('token') ?? '');
        $password = (string) ($request->json('new_password') ?? '');
        $this->assertPassword($password);
        $userId = $this->redis->get('reset_password:'.$token);
        if (! $userId) {
            throw new ApiException('Invalid or expired reset token', 400);
        }
        $user = User::query()->find((int) $userId);
        if (! $user) {
            throw new ApiException('User not found', 404);
        }
        $user->hashed_password = PasswordHasher::hash($password);
        $user->save();
        $this->redis->delete('reset_password:'.$token);

        return response()->json(['detail' => 'Password updated successfully']);
    }

    private function syncTargetLanguage(User $user, string $target): void
    {
        $langs = $this->languages->all($user->id);
        $existing = null;
        foreach ($langs as $lang) {
            if ($lang->target_language === $target) {
                $existing = $lang;
                break;
            }
        }
        if ($existing) {
            $this->languages->switch($user->id, $target);

            return;
        }
        $prefix = explode('-', $target)[0];
        $sameBase = null;
        foreach ($langs as $lang) {
            if (explode('-', $lang->target_language)[0] === $prefix) {
                $sameBase = $lang;
                break;
            }
        }
        if ($sameBase) {
            UserLanguage::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);
            $sameBase->target_language = $target;
            $sameBase->is_active = true;
            $sameBase->save();

            return;
        }
        $this->languages->add($user->id, $target);
    }

    private function withRefresh(JsonResponse $response, string $refresh, int $ttl): JsonResponse
    {
        return $response->cookie(
            'refresh_token',
            $refresh,
            (int) ceil($ttl / 60),
            '/',
            null,
            Settings::bool('cookie_secure'),
            true,
            false,
            'lax'
        );
    }

    private function assertUsername(string $username): void
    {
        if (strlen($username) < 3 || strlen($username) > 50 || ! preg_match('/^[\w.-]+$/', $username)) {
            throw new ApiException('Invalid username', 422);
        }
    }

    private function assertEmail(string $email): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('Invalid email', 422);
        }
    }

    private function assertPassword(string $password): void
    {
        if (! preg_match(self::PASSWORD_PATTERN, $password)) {
            throw new ApiException(
                'Password must be 10-25 characters and include at least one uppercase letter, one number, and one symbol',
                422
            );
        }
    }

    private function assertNative(string $language): void
    {
        if (! in_array($language, self::SUPPORTED_LANGUAGES, true)) {
            throw new ApiException('Unsupported language code: '.$language, 422);
        }
    }

    private function assertTarget(string $language): void
    {
        if (! in_array($language, self::SUPPORTED_TARGET_LANGUAGES, true)) {
            throw new ApiException('Unsupported target language', 422);
        }
    }
}
