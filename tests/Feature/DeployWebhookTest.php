<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Storage;

class DeployWebhookTest extends TestCase
{
    public function test_webhook_queues_only_signed_pushes_to_the_configured_prod_branch(): void
    {
        $secret = str_repeat('test-secret-', 5);
        config(['framekeep.deploy_webhook.secret' => $secret, 'framekeep.deploy_webhook.repository' => 'owner/framekeep']);
        Storage::fake('local');
        $payload = ['repository' => ['full_name' => 'owner/framekeep'], 'ref' => 'refs/heads/prod',
            'after' => str_repeat('a', 40), 'deleted' => false];

        $this->deliver($payload, 'push', 'invalid')->assertUnauthorized();
        $this->deliver(array_replace($payload, ['repository' => ['full_name' => 'another/framekeep']]), 'push', $secret)
            ->assertBadRequest();
        $this->deliver(array_replace($payload, ['ref' => 'refs/heads/stage']), 'push', $secret)
            ->assertOk()->assertJsonPath('queued', false);
        $this->deliver(array_replace($payload, ['deleted' => true]), 'push', $secret)->assertOk()->assertJsonPath('queued', false);
        $this->deliver($payload, 'ping', $secret)->assertOk()->assertJsonPath('received', true);
        Storage::disk('local')->assertMissing('prod-deploy-request');

        $this->deliver($payload, 'push', $secret)->assertAccepted()->assertJsonPath('queued', true);
        $this->assertSame($payload['after']."\n", Storage::disk('local')->get('prod-deploy-request'));
    }

    private function deliver(array $payload, string $event, string $secret): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/github', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], content: $body);
    }
}
