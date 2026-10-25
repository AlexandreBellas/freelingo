<?php

namespace App\Services\Billing;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

class HttpStripeGateway implements StripeGateway
{
    public function createCustomer(string $email, string $name, string $userId): array
    {
        return $this->form('POST', '/customers', [
            'email' => $email,
            'name' => $name,
            'metadata' => ['user_id' => $userId],
        ]);
    }

    public function createCheckoutSession(array $params): array
    {
        return $this->form('POST', '/checkout/sessions', $params);
    }

    public function createPortalSession(array $params): array
    {
        return $this->form('POST', '/billing_portal/sessions', $params);
    }

    public function constructEvent(string $payload, string $signature, string $secret): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid payload');
        }
        if (! is_array($decoded) || ! $this->signatureValid($payload, $signature, $secret)) {
            throw new \RuntimeException('Invalid signature');
        }

        return $decoded;
    }

    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->form('GET', '/subscriptions/'.rawurlencode($subscriptionId), []);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function form(string $method, string $path, array $params): array
    {
        $request = Http::withToken(Settings::string('stripe_secret_key'))
            ->asForm()
            ->acceptJson()
            ->timeout(20);
        $url = 'https://api.stripe.com/v1'.$path;
        $response = $method === 'GET' ? $request->get($url) : $request->post($url, $params);
        if (! $response->successful()) {
            throw new \RuntimeException('Stripe request failed');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('Stripe request failed');
        }

        return $json;
    }

    private function signatureValid(string $payload, string $header, string $secret): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) !== 2) {
                continue;
            }
            if ($parts[0] === 't') {
                $timestamp = $parts[1];
            } elseif ($parts[0] === 'v1') {
                $signatures[] = $parts[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
