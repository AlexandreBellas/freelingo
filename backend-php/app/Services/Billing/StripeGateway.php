<?php

namespace App\Services\Billing;

interface StripeGateway
{
    /**
     * @return array<string, mixed>
     */
    public function createCustomer(string $email, string $name, string $userId): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createPortalSession(array $params): array;

    /**
     * @return array<string, mixed>
     */
    public function constructEvent(string $payload, string $signature, string $secret): array;

    /**
     * @return array<string, mixed>
     */
    public function retrieveSubscription(string $subscriptionId): array;
}
