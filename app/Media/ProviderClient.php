<?php

namespace App\Media;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ProviderClient
{
    public function json(string $url, array $query = [], ?string $bearer = null): array
    {
        $request = Http::acceptJson()->withoutRedirecting()->connectTimeout(4)->timeout(12);
        if ($bearer !== null) {
            $request->withToken($bearer);
        }
        try {
            $response = $request->get($url, $query);
        } catch (ConnectionException) {
            throw new ProviderException('The platform could not be reached.', true);
        }
        if ($response->tooManyRequests() || $response->serverError()) {
            throw new ProviderException('The platform is temporarily unavailable or rate limiting imports.', true);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new ProviderException('The platform did not authorize or return this source.');
        }

        return $response->json();
    }
}
