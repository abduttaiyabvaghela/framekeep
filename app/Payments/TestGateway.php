<?php

namespace App\Payments;

use App\Models\TestCheckout;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

enum TestGateway: string
{
    case Stripe = 'stripe';
    case Razorpay = 'razorpay';

    public function ready(): bool
    {
        $settings = $this->settings();

        return config('test-payments.enabled')
            && preg_match($this === self::Stripe ? '/^sk_test_[A-Za-z0-9]+$/D' : '/^rzp_test_[A-Za-z0-9]+$/D', (string) $settings['key']) === 1
            && preg_match($this === self::Stripe ? '/^price_[A-Za-z0-9]+$/D' : '/^plan_[A-Za-z0-9]+$/D', (string) $settings['price']) === 1
            && strlen((string) $settings['webhook_secret']) >= 16
            && ($this === self::Stripe || filled($settings['secret']));
    }

    public function create(TestCheckout $checkout, User $user): string
    {
        $price = $this->settings()['price'];
        $plan = $this->api($this === self::Stripe ? 'prices/'.$price : 'plans/'.$price);
        abort_unless($this->annual($plan), 503, 'Configure a $20 USD yearly test plan on this gateway.');
        if ($this === self::Stripe) {
            $home = rtrim(config('app.url'), '/');
            $data = $this->api('checkout/sessions', [
                'mode' => 'subscription', 'payment_method_types' => ['card'],
                'customer_email' => $user->email, 'line_items' => [['price' => $price, 'quantity' => 1]],
                'subscription_data' => ['metadata' => ['framekeep_checkout' => $checkout->id]],
                'success_url' => $home.'/?payment=awaiting', 'cancel_url' => $home.'/?payment=cancelled',
                'expires_at' => $checkout->expires_at->timestamp,
            ], $checkout->id);
            abort_unless(($data['livemode'] ?? null) === false, 502, 'Stripe did not return a test checkout.');
            $url = $data['url'] ?? null;
        } else {
            $data = $this->api('subscriptions', [
                'plan_id' => $price, 'quantity' => 1, 'total_count' => 5, 'customer_notify' => 0,
                'expire_by' => $checkout->expires_at->timestamp, 'notes' => ['framekeep_checkout' => $checkout->id],
            ]);
            $url = $data['short_url'] ?? null;
            abort_unless($this->validSubscription($data['id'] ?? null), 502, 'Razorpay did not return a test subscription.');
            abort_unless(($data['plan_id'] ?? null) === $price, 502, 'Razorpay returned a different plan.');
            $checkout->external_id = $data['id'];
        }
        $hosts = $this === self::Stripe ? ['checkout.stripe.com'] : ['rzp.io', 'rzp.page', 'api.razorpay.com'];
        $uri = is_string($url) ? parse_url($url) : null;
        abort_unless($uri && ($uri['scheme'] ?? '') === 'https' && in_array($uri['host'] ?? '', $hosts, true)
            && ! isset($uri['user']) && ! isset($uri['port']), 502, 'The gateway returned an invalid checkout link.');
        $checkout->url = $url;
        $checkout->save();

        return $url;
    }

    public function eventSubscription(Request $request): ?string
    {
        $body = $request->getContent();
        abort_if(strlen($body) > 2_000_000, 413);
        $secret = $this->settings()['webhook_secret'];
        if ($this === self::Stripe) {
            $signature = (string) $request->header('Stripe-Signature');
            preg_match('/(?:^|,)t=(\d+)(?:,|$)/', $signature, $time);
            $timestamp = (int) ($time[1] ?? 0);
            preg_match_all('/(?:^|,)v1=([a-f0-9]{64})(?=,|$)/', $signature, $signatures);
            $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
            abort_unless(abs(time() - $timestamp) <= 300
                && array_filter($signatures[1], fn (string $candidate) => hash_equals($expected, $candidate)), 401);
            $event = json_decode($body, true);
            abort_unless(is_array($event) && ($event['livemode'] ?? null) === false, 400);
            $data = $event['data']['object'] ?? [];
            $id = match ($event['type'] ?? null) {
                'checkout.session.completed' => $data['subscription'] ?? null,
                'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $data['id'] ?? null,
                'invoice.paid', 'invoice.payment_failed' => $data['parent']['subscription_details']['subscription'] ?? $data['subscription'] ?? null,
                default => null,
            };
        } else {
            abort_unless(hash_equals(hash_hmac('sha256', $body, $secret), (string) $request->header('X-Razorpay-Signature')), 401);
            $event = json_decode($body, true);
            abort_unless(is_array($event), 400);
            $id = in_array($event['event'] ?? null, ['subscription.charged', 'subscription.activated', 'subscription.cancelled',
                'subscription.halted', 'subscription.completed', 'subscription.paused', 'subscription.resumed', 'subscription.pending'], true)
                ? ($event['payload']['subscription']['entity']['id'] ?? null)
                : null;
        }

        return $this->validSubscription($id) ? $id : null;
    }

    public function reconcile(string $id): void
    {
        $data = $this->api('subscriptions/'.$id, query: $this === self::Stripe ? ['expand' => ['latest_invoice']] : []);
        if (($data['id'] ?? null) !== $id) {
            return;
        }
        if ($this === self::Stripe) {
            $line = $data['items']['data'][0] ?? [];
            $paid = ($data['livemode'] ?? null) === false && ($data['status'] ?? null) === 'active'
                && count($data['items']['data'] ?? []) === 1 && ($line['quantity'] ?? null) === 1
                && $this->annual($line['price'] ?? [])
                && ($data['latest_invoice']['status'] ?? null) === 'paid'
                && ($data['latest_invoice']['amount_paid'] ?? 0) >= config('framekeep.offer.amount_minor');
            $end = (int) ($line['current_period_end'] ?? $data['current_period_end'] ?? 0);
            $reference = $data['metadata']['framekeep_checkout'] ?? null;
        } else {
            $paid = ($data['status'] ?? null) === 'active' && ($data['plan_id'] ?? null) === $this->settings()['price']
                && (int) ($data['quantity'] ?? 0) === 1 && (int) ($data['paid_count'] ?? 0) > 0;
            $end = (int) ($data['current_end'] ?? 0);
            $reference = $data['notes']['framekeep_checkout'] ?? null;
        }
        if (! is_string($reference) || ! preg_match('/^[a-f0-9-]{36}$/D', $reference)) {
            return;
        }
        TestCheckout::whereKey($reference)->where('gateway', $this->value)
            ->where(fn ($query) => $query->whereNull('external_id')->orWhere('external_id', $id))
            ->update(['external_id' => $id, 'paid_until' => $paid && $end > time() ? now()->setTimestamp($end) : null]);
    }

    private function validSubscription(mixed $value): bool
    {
        return is_string($value) && preg_match('/^sub_[A-Za-z0-9]{1,90}$/D', $value) === 1;
    }

    private function annual(array $price): bool
    {
        if (($price['id'] ?? null) !== $this->settings()['price']) {
            return false;
        }
        $amount = config('framekeep.offer.amount_minor');

        return $this === self::Stripe
            ? ($price['livemode'] ?? null) === false && ($price['active'] ?? false) === true
                && ($price['currency'] ?? null) === 'usd' && ($price['unit_amount'] ?? null) === $amount
                && ($price['recurring']['interval'] ?? null) === 'year' && ($price['recurring']['interval_count'] ?? null) === 1
            : ($price['period'] ?? null) === 'yearly' && ($price['interval'] ?? null) === 1
                && ($price['item']['currency'] ?? null) === 'USD' && ($price['item']['amount'] ?? null) === $amount;
    }

    private function api(string $endpoint, ?array $data = null, ?string $idempotency = null, array $query = []): array
    {
        $settings = $this->settings();
        $request = Http::acceptJson()->withoutRedirecting()->connectTimeout(4)->timeout(10);
        if ($this === self::Stripe) {
            $url = 'https://api.stripe.com/v1/'.$endpoint;
            $request->withToken($settings['key'])->asForm()->withHeader('Stripe-Version', '2025-03-31.basil');
        } else {
            $url = 'https://api.razorpay.com/v1/'.$endpoint;
            $request->withBasicAuth($settings['key'], $settings['secret']);
        }
        if ($idempotency !== null) {
            $request->withHeader('Idempotency-Key', $idempotency);
        }
        try {
            $response = $data === null ? $request->get($url, $query) : $request->post($url, $data);
        } catch (ConnectionException) {
            abort(502, 'The test gateway is temporarily unreachable.');
        }
        abort_unless($response->successful() && is_array($response->json()), 502, 'The test gateway did not accept the request.');

        return $response->json();
    }

    private function settings(): array
    {
        return config('test-payments.'.$this->value);
    }
}
