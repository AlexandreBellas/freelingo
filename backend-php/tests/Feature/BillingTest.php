<?php

namespace Tests\Feature;

use App\Services\Billing\StripeGateway;
use Tests\TestCase;

class BillingTest extends TestCase
{
    public function test_checkout_and_portal(): void
    {
        [$user, $headers] = $this->makeUser();
        config([
            'freelingo.stripe_enabled' => true,
            'freelingo.stripe_price_monthly' => 'price_monthly_test',
            'freelingo.stripe_price_yearly' => 'price_yearly_test',
        ]);
        $gateway = new FakeStripe;
        $this->app->instance(StripeGateway::class, $gateway);

        $monthly = $this->api('POST', '/api/billing/checkout', ['plan' => 'monthly'], $headers);
        $monthly->assertOk();
        $monthly->assertJsonPath('url', 'https://checkout.stripe.com/pay/test');
        $this->assertNotNull($user->refresh()->stripe_customer_id);

        $gateway->checkoutUrl = 'https://checkout.stripe.com/pay/yearly_test';
        $this->api('POST', '/api/billing/checkout', ['plan' => 'yearly'], $headers)
            ->assertOk()
            ->assertJsonPath('url', 'https://checkout.stripe.com/pay/yearly_test');
        $this->assertSame(1, $gateway->customers);

        config(['freelingo.stripe_price_monthly' => '', 'freelingo.stripe_price_yearly' => '']);
        $this->api('POST', '/api/billing/checkout', ['plan' => 'monthly'], $headers)->assertStatus(503);

        $user->stripe_customer_id = null;
        $user->save();
        $this->api('POST', '/api/billing/portal', [], $headers)->assertStatus(400)->assertJsonPath('detail', 'No active subscription found');

        $user->stripe_customer_id = 'cus_past_due';
        $user->save();
        $this->api('POST', '/api/billing/portal', [], $headers)
            ->assertOk()
            ->assertJsonPath('url', 'https://billing.stripe.com/session/test');
        $this->assertSame('cus_past_due', $gateway->portalCustomer);
    }

    public function test_subscription_service_rules_via_webhook(): void
    {
        [$user] = $this->makeUser();
        $user->stripe_customer_id = 'cus_webhook1';
        $user->save();
        $gateway = new FakeStripe;
        $gateway->subscription = ['status' => 'trialing', 'current_period_end' => 1798675200];
        $this->app->instance(StripeGateway::class, $gateway);

        $event = [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'customer' => 'cus_webhook1',
                'subscription' => 'sub_test1',
                'metadata' => ['user_id' => (string) $user->id],
            ]],
        ];
        $this->api('POST', '/api/billing/webhook', $event, ['stripe-signature' => 't=1,v1=fakesig'])
            ->assertOk()
            ->assertJsonPath('received', true);
        $user->refresh();
        $this->assertSame('trialing', $user->subscription_status);
        $this->assertSame('sub_test1', $user->stripe_subscription_id);
        $this->assertTrue($user->trial_used);

        $gateway->throwOnRetrieve = true;
        $user->subscription_status = 'none';
        $user->stripe_customer_id = 'cus_lookup_fail';
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['customer' => 'cus_lookup_fail', 'subscription' => 'sub_lookup_fail']],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertStatus(500);
        $this->assertSame('none', $user->refresh()->subscription_status);

        $user->stripe_customer_id = 'cus_webhook2';
        $user->stripe_subscription_id = 'sub_current';
        $user->subscription_status = 'trialing';
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_current',
                'customer' => 'cus_webhook2',
                'status' => 'active',
                'current_period_end' => 1798675200,
            ]],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $this->assertSame('active', $user->refresh()->subscription_status);

        $this->api('POST', '/api/billing/webhook', [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_old', 'customer' => 'cus_webhook2', 'status' => 'canceled']],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $this->assertSame('active', $user->refresh()->subscription_status);
        $this->assertSame('sub_current', $user->stripe_subscription_id);

        $user->stripe_subscription_id = null;
        $user->subscription_status = 'trialing';
        $user->stripe_customer_id = 'cus_bind_update';
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_bound', 'customer' => 'cus_bind_update', 'status' => 'active', 'current_period_end' => 1798675200]],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $user->refresh();
        $this->assertSame('active', $user->subscription_status);
        $this->assertSame('sub_bound', $user->stripe_subscription_id);

        foreach (['incomplete', 'incomplete_expired', 'unpaid', 'paused'] as $status) {
            $user->stripe_customer_id = 'cus_'.$status;
            $user->stripe_subscription_id = null;
            $user->subscription_status = 'active';
            $user->save();
            $this->api('POST', '/api/billing/webhook', [
                'type' => 'customer.subscription.updated',
                'data' => ['object' => ['customer' => 'cus_'.$status, 'status' => $status, 'current_period_end' => 1798675200]],
            ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
            $this->assertSame($status, $user->refresh()->subscription_status);
        }

        $user->stripe_customer_id = 'cus_unknown_status';
        $user->subscription_status = 'past_due';
        $user->stripe_subscription_id = null;
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => 'cus_unknown_status', 'status' => 'unexpected_status']],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $this->assertSame('past_due', $user->refresh()->subscription_status);

        $user->stripe_customer_id = 'cus_webhook3';
        $user->stripe_subscription_id = 'sub_deleted';
        $user->subscription_status = 'active';
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_deleted', 'customer' => 'cus_webhook3']],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $this->assertSame('canceled', $user->refresh()->subscription_status);

        $user->subscription_status = 'active';
        $user->stripe_subscription_id = 'sub_current';
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_old', 'customer' => 'cus_webhook3']],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $this->assertSame('active', $user->refresh()->subscription_status);

        $user->stripe_customer_id = 'cus_webhook4';
        $user->stripe_subscription_id = 'sub_failed';
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_webhook4', 'subscription' => 'sub_failed']],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $this->assertSame('past_due', $user->refresh()->subscription_status);

        $user->subscription_status = 'active';
        $user->stripe_customer_id = 'cus_invoice_parent';
        $user->stripe_subscription_id = 'sub_parent';
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'customer' => 'cus_invoice_parent',
                'parent' => ['subscription_details' => ['subscription' => 'sub_parent']],
            ]],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $this->assertSame('past_due', $user->refresh()->subscription_status);

        $user->subscription_status = 'active';
        $user->stripe_subscription_id = 'sub_current';
        $user->stripe_customer_id = 'cus_stale_invoice';
        $user->save();
        $this->api('POST', '/api/billing/webhook', [
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_stale_invoice', 'subscription' => 'sub_old']],
        ], ['stripe-signature' => 't=1,v1=fakesig'])->assertOk();
        $this->assertSame('active', $user->refresh()->subscription_status);
    }

    public function test_webhook_rejects_bad_signature_and_payload(): void
    {
        $badSignature = $this->withHeaders(['stripe-signature' => 't=1,v1=fakesig', 'content-type' => 'application/json'])
            ->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $badSignature->assertStatus(400);
        $this->assertStringContainsString('signature', strtolower((string) $badSignature->json('detail')));

        $badPayload = $this->withHeaders(['stripe-signature' => 't=1,v1=fakesig', 'content-type' => 'application/json'])
            ->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json');
        $badPayload->assertStatus(400);
        $this->assertStringContainsString('payload', strtolower((string) $badPayload->json('detail')));
    }
}

class FakeStripe implements StripeGateway
{
    public int $customers = 0;

    public string $checkoutUrl = 'https://checkout.stripe.com/pay/test';

    public ?string $portalCustomer = null;

    public bool $throwOnRetrieve = false;

    /** @var array<string, mixed> */
    public array $subscription = ['status' => 'active'];

    public function createCustomer(string $email, string $name, string $userId): array
    {
        $this->customers++;

        return ['id' => 'cus_test123'];
    }

    public function createCheckoutSession(array $params): array
    {
        return ['url' => $this->checkoutUrl];
    }

    public function createPortalSession(array $params): array
    {
        $this->portalCustomer = $params['customer'] ?? null;

        return ['url' => 'https://billing.stripe.com/session/test'];
    }

    public function constructEvent(string $payload, string $signature, string $secret): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid payload');
        }

        return $decoded;
    }

    public function retrieveSubscription(string $subscriptionId): array
    {
        if ($this->throwOnRetrieve) {
            throw new \RuntimeException('stripe unavailable');
        }

        return $this->subscription;
    }
}
