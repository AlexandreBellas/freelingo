<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\ChatHistory;
use App\Models\FeedbackEntry;
use App\Models\Lesson;
use App\Models\LlmUsage;
use App\Models\Progress;
use App\Models\Review;
use App\Models\StudyPlan;
use App\Models\User;
use App\Models\UserLanguage;
use App\Services\Email\EmailService;
use App\Services\QuotaService;
use App\Services\Redis\ArrayRedis;
use App\Services\Security\PasswordHasher;
use App\Services\SubscriptionService;
use App\Support\Iso;
use App\Support\QueryParams;
use App\Support\Settings;
use App\Support\TargetLanguages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    use ResolvesApiUser;

    private const SUBSCRIPTION_STATUSES = [
        'none', 'trialing', 'active', 'past_due', 'canceled', 'incomplete', 'incomplete_expired', 'unpaid', 'paused',
    ];

    public function __construct(
        private readonly ArrayRedis $redis,
        private readonly EmailService $email,
        private readonly QuotaService $quotas,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $this->apiUser($request);

        return response()->json([
            'users_total' => User::query()->count(),
            'users_active' => User::query()->where('is_active', true)->count(),
            'users_inactive' => User::query()->where('is_active', false)->count(),
            'subscriptions_active' => User::query()->where('subscription_status', 'active')->count(),
            'subscriptions_trialing' => User::query()->where('subscription_status', 'trialing')->count(),
            'subscriptions_past_due' => User::query()->where('subscription_status', 'past_due')->count(),
            'feedback_total' => FeedbackEntry::query()->count(),
            'feedback_pending' => FeedbackEntry::query()->where('status', 'pending')->count(),
            'feedback_bug_pending' => FeedbackEntry::query()->where('type', 'bug')->where('status', 'pending')->count(),
            'reviews_pending' => Review::query()->where('is_approved', false)->count(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->apiUser($request);
        $skip = QueryParams::int($request, 'skip', 0, 0);
        $limit = QueryParams::int($request, 'limit', 10, 1, 100);
        $q = QueryParams::boundedString($request, 'q', '', 100) ?? '';
        $role = QueryParams::optionalEnum($request, 'role', ['user', 'admin']);
        $active = QueryParams::bool($request, 'is_active');
        $subscription = QueryParams::optionalEnum($request, 'subscription', self::SUBSCRIPTION_STATUSES);

        $query = User::query();
        if (trim($q) !== '') {
            $pattern = '%'.trim($q).'%';
            $query->where(function ($inner) use ($pattern) {
                $inner->where('username', 'like', $pattern)->orWhere('email', 'like', $pattern);
            });
        }
        if ($role) {
            $query->where('role', $role);
        }
        if ($active !== null) {
            $query->where('is_active', $active);
        }
        if ($subscription) {
            $query->where('subscription_status', $subscription);
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('username')->skip($skip)->take($limit)->get();

        return response()->json([
            'items' => $items->map(fn (User $user) => $this->present($user))->all(),
            'total' => $total,
            'skip' => $skip,
            'limit' => $limit,
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->apiUser($request);
        $data = $this->createPayload($request);
        if (User::query()->where('username', $data['username'])->exists()) {
            throw new ApiException('Username already taken', 409);
        }
        if (User::query()->where('email', $data['email'])->exists()) {
            throw new ApiException('Email already taken', 409);
        }
        $user = User::query()->create([
            'username' => $data['username'],
            'email' => $data['email'],
            'display_name' => $data['display_name'],
            'hashed_password' => PasswordHasher::hash($data['password']),
            'native_language' => $data['native_language'],
            'target_language' => $data['target_language'],
            'role' => $data['role'],
            'is_active' => true,
            'is_verified' => ! Settings::bool('email_enabled'),
            'created_at' => Carbon::now('UTC'),
        ]);
        UserLanguage::query()->create([
            'user_id' => $user->id,
            'target_language' => $data['target_language'],
            'is_active' => true,
            'created_at' => Carbon::now('UTC'),
        ]);
        if ($user->email && Settings::bool('email_enabled')) {
            $token = (string) Str::uuid();
            $this->redis->setex('verify_email:'.$token, 86400, (string) $user->id);
            $this->email->sendVerificationEmail($user->email, $user->display_name, $token, $user->native_language);
        }

        return response()->json($this->present($user->refresh()));
    }

    public function userStats(Request $request, int $userId): JsonResponse
    {
        $this->apiUser($request);
        $user = User::query()->find($userId);
        if (! $user) {
            throw new ApiException('User not found', 404);
        }
        $plan = StudyPlan::query()
            ->select('study_plans.*')
            ->join('user_languages', 'study_plans.user_language_id', '=', 'user_languages.id')
            ->where('user_languages.user_id', $userId)
            ->where('study_plans.is_active', true)
            ->orderByDesc('study_plans.created_at')
            ->first();
        $lessonsDone = Lesson::query()
            ->join('study_plans', 'lessons.study_plan_id', '=', 'study_plans.id')
            ->join('user_languages', 'study_plans.user_language_id', '=', 'user_languages.id')
            ->where('user_languages.user_id', $userId)
            ->where('lessons.is_completed', true)
            ->count();
        $prog = Progress::query()
            ->where('user_id', $userId)
            ->selectRaw('COALESCE(SUM(xp_earned), 0) as xp_total, COALESCE(MAX(streak_day), 0) as streak, COUNT(id) as active_days, COALESCE(SUM(exercises_correct), 0) as ex_correct, COALESCE(SUM(exercises_total), 0) as ex_total')
            ->first();
        $chatCount = ChatHistory::query()->where('user_id', $userId)->where('role', 'user')->count();
        $tokenRows = LlmUsage::query()
            ->where('user_id', $userId)
            ->selectRaw('source, COALESCE(SUM(total_tokens), 0) as total')
            ->groupBy('source')
            ->get();
        $tokens = [];
        foreach ($tokenRows as $row) {
            $tokens[$row->source] = (int) $row->total;
        }
        $plans = StudyPlan::query()
            ->select('study_plans.*')
            ->join('user_languages', 'study_plans.user_language_id', '=', 'user_languages.id')
            ->where('user_languages.user_id', $userId)
            ->get();
        $groups = [];
        foreach ($plans as $studyPlan) {
            $key = $studyPlan->target_language;
            if (! isset($groups[$key])) {
                $groups[$key] = ['cefr' => null, 'xp' => 0, 'streak' => 0, 'days' => 0, 'lessons' => 0, 'ex_correct' => 0, 'ex_total' => 0];
            }
            if ($studyPlan->is_active) {
                $groups[$key]['cefr'] = $studyPlan->cefr_level;
            }
            $lp = Progress::query()
                ->where('study_plan_id', $studyPlan->id)
                ->selectRaw('COALESCE(SUM(xp_earned), 0) as xp, COALESCE(MAX(streak_day), 0) as streak, COUNT(id) as days, COALESCE(SUM(lessons_completed), 0) as lessons, COALESCE(SUM(exercises_correct), 0) as ex_correct, COALESCE(SUM(exercises_total), 0) as ex_total')
                ->first();
            $groups[$key]['xp'] += (int) $lp->xp;
            $groups[$key]['streak'] = max($groups[$key]['streak'], (int) $lp->streak);
            $groups[$key]['days'] += (int) $lp->days;
            $groups[$key]['lessons'] += (int) $lp->lessons;
            $groups[$key]['ex_correct'] += (int) $lp->ex_correct;
            $groups[$key]['ex_total'] += (int) $lp->ex_total;
        }
        $perLanguage = [];
        foreach ($groups as $language => $group) {
            $perLanguage[] = [
                'target_language' => $language,
                'cefr_level' => $group['cefr'],
                'xp_total' => $group['xp'],
                'streak_current' => $group['streak'],
                'active_days' => $group['days'],
                'lessons_completed' => $group['lessons'],
                'exercises_correct' => $group['ex_correct'],
                'exercises_total' => $group['ex_total'],
            ];
        }

        return response()->json([
            'user_id' => $userId,
            'current_cefr' => $plan?->cefr_level,
            'current_unit' => $plan?->current_unit,
            'plan_duration_weeks' => $plan ? (int) $plan->duration_weeks : null,
            'completion_test_score' => $plan?->completion_test_score,
            'xp_total' => (int) ($prog->xp_total ?? 0),
            'streak_current' => (int) ($prog->streak ?? 0),
            'active_days' => (int) ($prog->active_days ?? 0),
            'lessons_completed' => $lessonsDone,
            'exercises_correct' => (int) ($prog->ex_correct ?? 0),
            'exercises_total' => (int) ($prog->ex_total ?? 0),
            'per_language' => $perLanguage,
            'chat_messages_sent' => $chatCount,
            'tokens_total' => array_sum($tokens),
            'tokens_chat' => $tokens['chat'] ?? 0,
            'tokens_conversation' => $tokens['conversation'] ?? 0,
        ]);
    }

    public function showUser(Request $request, int $userId): JsonResponse
    {
        $this->apiUser($request);
        $user = User::query()->find($userId);
        if (! $user) {
            throw new ApiException('User not found', 404);
        }

        return response()->json($this->present($user));
    }

    public function updateUser(Request $request, int $userId): JsonResponse
    {
        $admin = $this->apiUser($request);
        $user = User::query()->find($userId);
        if (! $user) {
            throw new ApiException('User not found', 404);
        }
        $data = $request->json()->all();
        if (! is_array($data)) {
            throw new ApiException('Validation error', 422);
        }
        if (array_key_exists('display_name', $data) && $data['display_name'] !== null) {
            if (! is_string($data['display_name']) || mb_strlen($data['display_name']) > 100) {
                throw new ApiException('Validation error', 422);
            }
            $user->display_name = $data['display_name'];
        }
        if (array_key_exists('role', $data) && $data['role'] !== null) {
            if (! in_array($data['role'], ['user', 'admin'], true)) {
                throw new ApiException('Validation error', 422);
            }
            $user->role = $data['role'];
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            if (! is_bool($data['is_active'])) {
                throw new ApiException('Validation error', 422);
            }
            if ($data['is_active'] === false && $user->id === $admin->id) {
                throw new ApiException('Cannot deactivate yourself', 400);
            }
            $user->is_active = $data['is_active'];
        }
        if (array_key_exists('is_verified', $data) && $data['is_verified'] !== null) {
            if (! is_bool($data['is_verified'])) {
                throw new ApiException('Validation error', 422);
            }
            $user->is_verified = $data['is_verified'];
        }
        foreach ([
            'conversation_weekly_sessions',
            'conversation_daily_minutes',
            'conversation_weekly_minutes',
            'monthly_tokens_limit',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                if (! is_int($data[$field]) || $data[$field] < 0) {
                    throw new ApiException('Validation error', 422);
                }
                $user->{$field} = $data[$field];
            }
        }
        if (array_key_exists('subscription_status', $data) && $data['subscription_status'] !== null) {
            if (! in_array($data['subscription_status'], self::SUBSCRIPTION_STATUSES, true)) {
                throw new ApiException('Validation error', 422);
            }
            $old = $user->subscription_status;
            $user->subscription_status = $data['subscription_status'];
            if ($data['subscription_status'] === 'none') {
                $user->subscription_ends_at = null;
            } elseif (array_key_exists('subscription_ends_at', $data) && $data['subscription_ends_at'] !== null) {
                $user->subscription_ends_at = $this->naiveDate($data['subscription_ends_at']);
            }
            if (in_array($data['subscription_status'], ['active', 'trialing'], true) && ! in_array($old, ['active', 'trialing'], true)) {
                $this->subscriptions->applyQuotas($user);
            }
        } elseif (array_key_exists('subscription_ends_at', $data) && $data['subscription_ends_at'] !== null) {
            $user->subscription_ends_at = $this->naiveDate($data['subscription_ends_at']);
        }
        $user->save();

        return response()->json($this->present($user->refresh()));
    }

    public function quota(Request $request, int $userId): JsonResponse
    {
        $this->apiUser($request);
        $user = User::query()->find($userId);
        if (! $user) {
            throw new ApiException('User not found', 404);
        }

        return response()->json($this->quotas->status(
            $userId,
            (int) $user->conversation_weekly_sessions,
            (int) $user->conversation_daily_minutes,
            (int) $user->conversation_weekly_minutes,
        ));
    }

    public function destroyUser(Request $request, int $userId): JsonResponse
    {
        $admin = $this->apiUser($request);
        $user = User::query()->find($userId);
        if (! $user) {
            throw new ApiException('User not found', 404);
        }
        if ($user->id === $admin->id) {
            throw new ApiException('Cannot delete yourself', 400);
        }
        $uid = (string) $userId;
        $cursor = 0;
        do {
            [$cursor, $keys] = $this->redis->scan($cursor, 'refresh:*', 100);
            foreach ($keys as $key) {
                if ($this->redis->get($key) === $uid) {
                    $this->redis->delete($key);
                }
            }
        } while ($cursor !== 0);
        $user->delete();

        return response()->json(['detail' => 'User deleted']);
    }

    public function invite(Request $request): JsonResponse
    {
        $this->apiUser($request);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->redis->setex('invite:'.$token, 172800, '1');

        return response()->json(['invite_url' => '/register?invite='.$token]);
    }

    public function maintenance(Request $request): JsonResponse
    {
        $this->apiUser($request);

        return response()->json(['maintenance_mode' => $this->redis->get('maintenance_mode') === '1']);
    }

    public function toggleMaintenance(Request $request): JsonResponse
    {
        $this->apiUser($request);
        $next = $this->redis->get('maintenance_mode') === '1' ? '0' : '1';
        $this->redis->set('maintenance_mode', $next);

        return response()->json(['maintenance_mode' => $next === '1']);
    }

    public function setMaintenance(Request $request): JsonResponse
    {
        $this->apiUser($request);
        $data = $request->json()->all();
        if (! is_array($data) || ! array_key_exists('maintenance_mode', $data) || ! is_bool($data['maintenance_mode'])) {
            throw new ApiException('Validation error', 422);
        }
        $this->redis->set('maintenance_mode', $data['maintenance_mode'] ? '1' : '0');

        return response()->json(['maintenance_mode' => $data['maintenance_mode']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(Request $request): array
    {
        $data = $request->json()->all();
        if (! is_array($data)) {
            throw new ApiException('Validation error', 422);
        }
        $username = $data['username'] ?? null;
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $display = $data['display_name'] ?? null;
        $native = $data['native_language'] ?? null;
        $target = $data['target_language'] ?? null;
        $role = $data['role'] ?? 'user';
        if (! is_string($username) || ! preg_match('/^[\w.-]+$/u', $username) || strlen($username) < 3 || strlen($username) > 50) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($password) || ! preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{10,25}$/', $password)) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($display) || mb_strlen($display) > 100) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($native) || ! in_array($native, TargetLanguages::NATIVE, true)) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($target) || strlen($target) < 2 || strlen($target) > 10) {
            throw new ApiException('Validation error', 422);
        }
        if (! in_array($role, ['user', 'admin'], true)) {
            throw new ApiException('Validation error', 422);
        }

        return compact('username', 'email', 'password', 'display', 'native', 'target', 'role') + [
            'display_name' => $display,
            'native_language' => $native,
            'target_language' => $target,
        ];
    }

    private function naiveDate(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new ApiException('Validation error', 422);
        }
        $stripped = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', trim($value));
        try {
            return Carbon::parse($stripped)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new ApiException('Validation error', 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'role' => $user->role,
            'native_language' => $user->native_language,
            'is_active' => (bool) $user->is_active,
            'is_verified' => (bool) $user->is_verified,
            'conversation_weekly_sessions' => (int) $user->conversation_weekly_sessions,
            'conversation_daily_minutes' => (int) $user->conversation_daily_minutes,
            'conversation_weekly_minutes' => (int) $user->conversation_weekly_minutes,
            'monthly_tokens_limit' => (int) $user->monthly_tokens_limit,
            'stripe_customer_id' => $user->stripe_customer_id,
            'stripe_subscription_id' => $user->stripe_subscription_id,
            'subscription_status' => $user->subscription_status ?? 'none',
            'subscription_ends_at' => Iso::string($user->subscription_ends_at),
            'created_at' => Iso::string($user->created_at),
            'last_login' => Iso::string($user->last_login),
        ];
    }
}
