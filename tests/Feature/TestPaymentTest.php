<?php

namespace Tests\Feature;

use App\Models\TestCheckout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;

class TestPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_test_checkout_grants_access_after_provider_confirmation(): void
    {
        config(['test-payments.enabled' => true, 'test-payments.stripe' => [
            'key' => 'sk_test_example', 'price' => 'price_example', 'webhook_secret' => 'whsec_teststripe1234',
        ]]);
        $this->signIn();
        $price = ['id' => 'price_example', 'active' => true, 'livemode' => false, 'currency' => 'usd', 'unit_amount' => 2000,
            'recurring' => ['interval' => 'year', 'interval_count' => 1]];
        $status = 'active';
        Http::preventStrayRequests();
        Http::fake([
            'api.stripe.com/v1/prices/price_example' => Http::response($price),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['livemode' => false, 'url' => 'https://checkout.stripe.com/c/pay/example']),
            'api.stripe.com/v1/subscriptions/sub_stripe*' => function () use ($price, &$status) {
                return Http::response(['id' => 'sub_stripe', 'livemode' => false, 'status' => $status,
                    'metadata' => ['framekeep_checkout' => TestCheckout::firstOrFail()->id],
                    'items' => ['data' => [['quantity' => 1, 'price' => $price, 'current_period_end' => now()->addYear()->timestamp]]],
                    'latest_invoice' => ['status' => 'paid', 'amount_paid' => 2000]]);
            },
        ]);
        $this->postJson('/api/v1/checkout', ['plan' => 'pro-annual', 'gateway' => 'stripe'])
            ->assertOk()->assertJsonPath('mode', 'test');
        $this->postJson('/api/v1/checkout', ['plan' => 'pro-annual', 'gateway' => 'stripe'])->assertOk();
        Http::assertSentCount(2);
        $this->getJson('/api/v1/me')->assertJsonPath('pro', false);
        $this->stripeEvent('checkout.session.completed', ['subscription' => 'sub_stripe'], 'wrong')->assertUnauthorized();
        $this->stripeEvent('checkout.session.completed', ['subscription' => 'sub_stripe'], 'whsec_teststripe1234')->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('pro', true);
        $status = 'canceled';
        $this->stripeEvent('customer.subscription.deleted', ['id' => 'sub_stripe'], 'whsec_teststripe1234')->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('pro', false);
    }

    public function test_razorpay_test_checkout_waits_for_a_paid_subscription(): void
    {
        config(['test-payments.enabled' => true, 'test-payments.razorpay' => [
            'key' => 'rzp_test_example', 'secret' => 'test-password', 'price' => 'plan_example', 'webhook_secret' => 'razorpay-hook-test-secret',
        ]]);
        $this->signIn();
        $paid = 0;
        Http::preventStrayRequests();
        Http::fake([
            'api.razorpay.com/v1/plans/plan_example' => Http::response(['id' => 'plan_example', 'period' => 'yearly',
                'interval' => 1, 'item' => ['currency' => 'USD', 'amount' => 2000]]),
            'api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_razorpay', 'plan_id' => 'plan_example', 'short_url' => 'https://rzp.io/i/example']),
            'api.razorpay.com/v1/subscriptions/sub_razorpay' => function () use (&$paid) {
                return Http::response(['id' => 'sub_razorpay', 'plan_id' => 'plan_example', 'status' => 'active', 'quantity' => 1,
                    'paid_count' => $paid, 'current_end' => now()->addYear()->timestamp,
                    'notes' => ['framekeep_checkout' => TestCheckout::firstOrFail()->id]]);
            },
        ]);
        $this->postJson('/api/v1/checkout', ['plan' => 'pro-annual', 'gateway' => 'razorpay'])
            ->assertOk()->assertJsonPath('url', 'https://rzp.io/i/example');
        $this->razorpayEvent('subscription.activated')->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('pro', false);
        $paid = 1;
        $this->razorpayEvent('subscription.charged')->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('pro', true);
        config(['test-payments.enabled' => false]);
        $this->getJson('/api/v1/me')->assertJsonPath('pro', false);
    }

    public function test_live_credentials_and_monthly_plans_are_rejected(): void
    {
        config(['test-payments.enabled' => true, 'test-payments.stripe' => [
            'key' => 'sk_live_example', 'price' => 'price_example', 'webhook_secret' => 'whsec_teststripe1234',
        ]]);
        $this->signIn();
        Http::preventStrayRequests();
        $this->getJson('/api/v1/catalog')->assertJsonPath('checkout_enabled', false);
        $this->postJson('/api/v1/checkout', ['plan' => 'pro-annual', 'gateway' => 'stripe'])->assertStatus(503);
        $this->postJson('/api/v1/checkout', ['plan' => 'pro-monthly', 'gateway' => 'stripe'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    private function signIn(): void
    {
        $this->actingAs(User::create(['email' => 'buyer@example.test', 'password' => 'unused-in-acting-as']));
    }

    private function stripeEvent(string $type, array $data, string $secret): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['livemode' => false, 'type' => $type, 'data' => ['object' => $data]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret);

        return $this->call('POST', '/api/v1/webhooks/payments/stripe', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature,
        ], content: $body);
    }

    private function razorpayEvent(string $type): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['event' => $type, 'payload' => ['subscription' => ['entity' => ['id' => 'sub_razorpay']]]], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/payments/razorpay', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'razorpay-hook-test-secret'),
        ], content: $body);
    }
}
