<?php

namespace App\Media;

class X
{
    public function __construct(private ProviderClient $http) {}

    public function page(string $source, ?array $cursor): ImportPage
    {
        $token = config('framekeep.x_token');
        $user = $cursor['user'] ?? null;
        if (! $user) {
            $profile = $this->http->json('https://api.x.com/2/users/by/username/'.rawurlencode($source), ['user.fields' => 'protected'], $token);
            $user = $profile['data']['id'] ?? null;
            if (! $user || ! empty($profile['data']['protected'])) {
                throw new ProviderException('X did not return a public user for that name.');
            }
        }
        $data = $this->http->json('https://api.x.com/2/users/'.rawurlencode($user).'/tweets', array_filter([
            'max_results' => 100, 'exclude' => 'retweets', 'expansions' => 'attachments.media_keys',
            'media.fields' => 'type,url,preview_image_url,variants,width,height',
            'tweet.fields' => 'created_at,attachments', 'pagination_token' => $cursor['page'] ?? null,
        ], fn ($value) => $value !== null), $token);
        $media = array_column($data['includes']['media'] ?? [], null, 'media_key');
        $items = [];
        foreach ($data['data'] ?? [] as $post) {
            foreach ($post['attachments']['media_keys'] ?? [] as $key) {
                if (! isset($media[$key], $post['id'])) {
                    continue;
                }
                $file = $media[$key];
                $image = $file['type'] === 'photo';
                $variants = [];
                foreach ($image ? [['url' => $file['url'] ?? null, 'content_type' => 'image']] : ($file['variants'] ?? []) as $variant) {
                    $url = Platform::X->mediaUrl($variant['url'] ?? null);
                    if (! $url || ! in_array($variant['content_type'] ?? '', ['video/mp4', 'image'], true)) {
                        continue;
                    }
                    preg_match('~/(\d{2,5})x(\d{2,5})/~', $url, $size);
                    $label = $size ? min((int) $size[1], (int) $size[2]).'p' : (! empty($variant['bit_rate'])
                        ? round($variant['bit_rate'] / 1000).' kbps' : 'Original');
                    $variants[$url] = ['label' => $label, 'url' => $url];
                }
                $items[] = [
                    'remote_id' => $post['id'].'-'.$key, 'type' => $image ? 'photo' : 'video',
                    'title' => $post['text'] ?? 'X media', 'permalink' => 'https://x.com/'.rawurlencode($source).'/status/'.$post['id'],
                    'thumbnail' => $file['url'] ?? $file['preview_image_url'] ?? null, 'published_at' => $post['created_at'] ?? null,
                    'variants' => array_values($variants),
                ];
            }
        }
        $next = $data['meta']['next_token'] ?? null;

        return new ImportPage('@'.$source, $items, $next ? ['user' => $user, 'page' => $next] : null);
    }
}
