<?php

namespace App\Media;

use App\Models\InstagramConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class InstagramProfessional
{
    public function __construct(private ProviderClient $http) {}

    public function exchange(string $code, string $redirect): array
    {
        $settings = config('framekeep.instagram');
        try {
            $short = Http::asForm()->acceptJson()->withoutRedirecting()->connectTimeout(4)->timeout(12)
                ->post('https://api.instagram.com/oauth/access_token', [
                    'client_id' => $settings['app_id'], 'client_secret' => $settings['secret'],
                    'grant_type' => 'authorization_code', 'redirect_uri' => $redirect, 'code' => $code,
                ]);
        } catch (ConnectionException) {
            throw new ProviderException('Instagram could not be reached.', true);
        }
        $shortToken = $short->json('access_token') ?? $short->json('data.0.access_token');
        if (! $short->successful() || ! is_string($shortToken) || $shortToken === '') {
            throw new ProviderException('Instagram did not authorize this connection.');
        }
        $long = $this->http->json('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token', 'client_secret' => $settings['secret'], 'access_token' => $shortToken,
        ]);
        $token = $long['access_token'] ?? '';
        $ttl = (int) ($long['expires_in'] ?? 0);
        if (! is_string($token) || $token === '' || $ttl <= 0) {
            throw new ProviderException('Instagram did not issue an account token.');
        }
        $profile = $this->http->json($this->graph('me'), ['fields' => 'user_id,username'], $token);
        $id = (string) ($profile['user_id'] ?? $profile['id'] ?? '');
        $name = $profile['username'] ?? '';
        if (! preg_match('/^\d{1,32}$/D', $id) || ! preg_match('/^[A-Za-z0-9._]{1,30}$/D', $name)) {
            throw new ProviderException('Instagram did not return a professional account.');
        }

        return ['account' => $id, 'username' => $name, 'token' => $token, 'expires_at' => now()->addSeconds($ttl)];
    }

    public function page(int $user, string $source, ?array $cursor): ImportPage
    {
        [$account, $edge] = explode(':', $source, 2);
        $connection = InstagramConnection::where(['user_id' => $user, 'account' => $account])->where('expires_at', '>', now())->first();
        if (! $connection) {
            throw new ProviderException('Reconnect this Instagram professional account.');
        }
        $fields = 'id,media_type,media_url,thumbnail_url,permalink,timestamp';
        if ($edge === 'media') {
            $fields .= ',caption,media_product_type,children{id,media_type,media_url,thumbnail_url}';
        }
        $query = ['fields' => $fields, 'limit' => 25];
        if (isset($cursor['after'])) {
            $query['after'] = $cursor['after'];
        }
        $page = $this->http->json($this->graph($connection->account.'/'.$edge), $query, $connection->token);
        $items = [];
        foreach ($page['data'] ?? [] as $parent) {
            $children = ($parent['media_type'] ?? null) === 'CAROUSEL_ALBUM' ? ($parent['children']['data'] ?? []) : [$parent];
            $permalink = $parent['permalink'] ?? '';
            if (! is_string($permalink) || ! preg_match('~^https://(?:www\.)?instagram\.com/[a-z0-9/_.-]+$~iD', $permalink)) {
                $permalink = 'https://www.instagram.com/'.$connection->username.'/';
            }
            foreach ($children as $child) {
                $id = (string) ($child['id'] ?? '');
                $asset = Platform::InstagramPro->mediaUrl($child['media_url'] ?? null);
                if (! $asset || ! preg_match('/^\d{1,32}$/D', $id)) {
                    continue;
                }
                $video = ($child['media_type'] ?? null) === 'VIDEO';
                $type = match (true) {
                    $edge === 'stories' => 'story',
                    ($parent['media_product_type'] ?? null) === 'REELS' || str_contains($permalink, '/reel/') => 'reel',
                    $video => 'video',
                    default => 'photo',
                };
                $items[] = ['remote_id' => $id, 'type' => $type, 'title' => ($parent['caption'] ?? '') ?: 'Instagram '.$type,
                    'permalink' => $permalink, 'thumbnail' => $video ? ($child['thumbnail_url'] ?? null) : $asset,
                    'published_at' => $parent['timestamp'] ?? null, 'variants' => [['url' => $asset, 'label' => 'Original']]];
            }
        }
        $next = ! empty($page['paging']['next']) ? ($page['paging']['cursors']['after'] ?? null) : null;

        return new ImportPage('@'.$connection->username.($edge === 'stories' ? ' · Stories' : ' · Posts and reels'),
            $items, is_string($next) && $next !== '' ? ['after' => $next] : null);
    }

    private function graph(string $path): string
    {
        return 'https://graph.instagram.com/'.config('framekeep.instagram.version').'/'.$path;
    }
}
