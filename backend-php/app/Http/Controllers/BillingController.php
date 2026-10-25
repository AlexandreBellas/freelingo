<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\User;
use App\Services\Billing\StripeGateway;
use App\Services\SubscriptionService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BillingController extends Controller
{
    use ResolvesApiUser;

    private const STATUSES = [
        'active', 'canceled', 'incomplete', 'incomplete_expired', 'past_due', 'paused', 'trialing', 'unpaid',
    ];

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function checkout(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $plan = $request->json('plan');
        if (! in_array($plan, ['monthly', 'yearly'], true)) {
            throw new ApiException('Validation error', 422);
        }
        $price = $plan === 'monthly' ? Settings::string('stripe_price_monthly') : Settings::string('stripe_price_yearly');
        if ($price === '') {
            throw new ApiException('Stripe prices not configured', 503);
        }
        $customerId = $user->stripe_customer_id;
        if (! $customerId) {
            $customer = $this->stripe->createCustomer($user->email ?? '', $user->display_name, (string) $user->id);
            $customerId = (string) ($customer['id'] ?? '');
            $user->stripe_customer_id = $customerId;
            $user->save();
        }
        $subscriptionData = ['metadata' => ['user_id' => (string) $user->id]];
        if (Settings::int('stripe_trial_days', 7) > 0 && ! $user->trial_used) {
            $subscriptionData['trial_period_days'] = Settings::int('stripe_trial_days', 7);
        }
        $session = $this->stripe->createCheckoutSession([
            'customer' => $customerId,
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'mode' => 'subscription',
            'locale' => 'auto',
            'allow_promotion_codes' => true,
            'metadata' => ['user_id' => (string) $user->id],
            'subscription_data' => $subscriptionData,
            'success_url' => Settings::string('stripe_base_url').'/billing/success',
            'cancel_url' => Settings::string('stripe_base_url').'/billing/canceled',
        ]);

        return response()->json(['url' => $session['url'] ?? null]);
    }

    public function portal(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        if (! $user->stripe_customer_id) {
            throw new ApiException('No active subscription found', 400);
        }
        $session = $this->stripe->createPortalSession([
            'customer' => $user->stripe_customer_id,
            'return_url' => Settings::string('stripe_base_url').'/settings',
        ]);

        return response()->json(['url' => $session['url'] ?? null]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->headers->get('stripe-signature', '');
        try {
            $event = $this->stripe->constructEvent($payload, $signature, Settings::string('stripe_webhook_secret'));
        } catch (\InvalidArgumentException) {
            throw new ApiException('Invalid payload', 400);
        } catch (\RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'signature')) {
                throw new ApiException('Invalid signature', 400);
            }
            throw new ApiException('Invalid payload', 400);
        }
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];
        if (! is_array($object)) {
            $object = [];
        }
        try {
            match ($type) {
                'checkout.session.completed' => $this->checkoutCompleted($object),
                'customer.subscription.updated' => $this->subscriptionUpdated($object),
                'customer.subscription.deleted' => $this->subscriptionDeleted($object),
                'invoice.payment_failed' => $this->paymentFailed($object),
                default => null,
            };
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ApiException('Webhook processing failed', 500);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function checkoutCompleted(array $session): void
    {
        $customerId = $this->stringOrNull($session['customer'] ?? null);
        $subscriptionId = $this->stringOrNull($session['subscription'] ?? null);
        if (! $customerId) {
            return;
        }
        $user = User::query()->where('stripe_customer_id', $customerId)->first();
        if (! $user) {
            $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
            $userId = $metadata['user_id'] ?? null;
            if (is_numeric($userId)) {
                $user = User::query()->find((int) $userId);
                if ($user) {
                    $user->stripe_customer_id = $customerId;
                }
            }
        }
        if (! $user || ! $subscriptionId) {
            return;
        }
        $user->stripe_subscription_id = $subscriptionId;
        $sub = $this->stripe->retrieveSubscription($subscriptionId);
        $status = $this->normalize($sub['status'] ?? null, 'none');
        $user->subscription_status = $status;
        $user->subscription_ends_at = $this->periodEnd($sub);
        $user->cancel_at_period_end = false;
        if ($status === 'trialing') {
            $user->trial_used = true;
        }
        $this->subscriptions->applyQuotas($user);
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionUpdated(array $subscription): void
    {
        $customerId = $this->stringOrNull($subscription['customer'] ?? null);
        if (! $customerId) {
            return;
        }
        $user = User::query()->where('stripe_customer_id', $customerId)->first();
        if (! $user || ! $this->current($user, $this->stringOrNull($subscription['id'] ?? null), true)) {
            return;
        }
        $user->subscription_status = $this->normalize($subscription['status'] ?? null, (string) $user->subscription_status);
        $cancelAt = $subscription['cancel_at'] ?? null;
        $user->cancel_at_period_end = (bool) ($subscription['cancel_at_period_end'] ?? false) || ($cancelAt !== null && $cancelAt !== 0);
        $ends = $this->periodEnd($subscription);
        if ($ends !== null) {
            $user->subscription_ends_at = $ends;
        }
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionDeleted(array $subscription): void
    {
        $customerId = $this->stringOrNull($subscription['customer'] ?? null);
        if (! $customerId) {
            return;
        }
        $user = User::query()->where('stripe_customer_id', $customerId)->first();
        if (! $user || ! $this->current($user, $this->stringOrNull($subscription['id'] ?? null), false)) {
            return;
        }
        $user->subscription_status = 'canceled';
        $user->cancel_at_period_end = false;
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function paymentFailed(array $invoice): void
    {
        $customerId = $this->stringOrNull($invoice['customer'] ?? null);
        if (! $customerId) {
            return;
        }
        $user = User::query()->where('stripe_customer_id', $customerId)->first();
        if (! $user || ! $this->current($user, $this->invoiceSubscriptionId($invoice), false)) {
            return;
        }
        $user->subscription_status = 'past_due';
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function invoiceSubscriptionId(array $invoice): ?string
    {
        $direct = $this->stringOrNull($invoice['subscription'] ?? null);
        if ($direct) {
            return $direct;
        }
        $parent = $invoice['parent'] ?? null;
        if (! is_array($parent)) {
            return null;
        }
        $details = $parent['subscription_details'] ?? null;
        if (! is_array($details)) {
            return null;
        }

        return $this->stringOrNull($details['subscription'] ?? null);
    }

    private function current(User $user, ?string $eventSubscriptionId, bool $bindIfMissing): bool
    {
        if (! $eventSubscriptionId) {
            return true;
        }
        if ($user->stripe_subscription_id) {
            return $user->stripe_subscription_id === $eventSubscriptionId;
        }
        if ($bindIfMissing) {
            $user->stripe_subscription_id = $eventSubscriptionId;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function periodEnd(array $subscription): ?string
    {
        $periodEnd = $subscription['current_period_end'] ?? null;
        if ($periodEnd === null) {
            $items = $subscription['items'] ?? null;
            $data = is_array($items) ? ($items['data'] ?? []) : [];
            if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                $periodEnd = $data[0]['current_period_end'] ?? null;
            }
        }
        if (! is_numeric($periodEnd)) {
            return null;
        }

        return Carbon::createFromTimestampUTC((int) $periodEnd)->format('Y-m-d H:i:s');
    }

    private function normalize(mixed $status, string $fallback): string
    {
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            return $fallback;
        }

        return $status;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
