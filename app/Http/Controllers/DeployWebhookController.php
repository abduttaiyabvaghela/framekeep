<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DeployWebhookController
{
    public function receive(Request $request): JsonResponse
    {
        $secret = (string) config('framekeep.deploy_webhook.secret');
        $repository = (string) config('framekeep.deploy_webhook.repository');
        abort_unless(strlen($secret) >= 32 && $repository !== '', 404);

        $body = $request->getContent();
        abort_if(strlen($body) > 2_000_000, 413);
        $signature = (string) $request->header('X-Hub-Signature-256');
        abort_unless(hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature), 401);

        $data = json_decode($body, true);
        abort_unless(is_array($data) && ($data['repository']['full_name'] ?? null) === $repository, 400);
        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['received' => true]);
        }
        $revision = $data['after'] ?? null;
        if ($request->header('X-GitHub-Event') !== 'push' || ($data['ref'] ?? null) !== 'refs/heads/prod'
            || ($data['deleted'] ?? false) || ! is_string($revision) || ! preg_match('/^[0-9a-f]{40}$/D', $revision)
            || $revision === str_repeat('0', 40)) {
            return response()->json(['queued' => false]);
        }

        File::replace(Storage::disk('local')->path('prod-deploy-request'), $revision."\n", 0600);

        return response()->json(['queued' => true], 202);
    }
}
