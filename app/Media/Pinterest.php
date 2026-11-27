<?php

namespace App\Media;

class Pinterest
{
    public function __construct(private ProviderClient $http) {}

    public function page(string $source, ?array $cursor): ImportPage
    {
        if (! in_array($source, config('framekeep.pinterest_boards'), true)) {
            throw new ProviderException('This Pinterest board is not approved for public imports.');
        }
        $token = config('framekeep.pinterest_token');
        $boardUrl = 'https://api.pinterest.com/v5/boards/'.rawurlencode($source);
        $board = $this->http->json($boardUrl, [], $token);
        if (strtoupper($board['privacy'] ?? '') !== 'PUBLIC') {
            throw new ProviderException('This Pinterest board is not publicly visible.');
        }
        $label = $board['name'] ?? $source;
        $data = $this->http->json($boardUrl.'/pins', array_filter([
            'page_size' => 100, 'bookmark' => $cursor['page'] ?? null,
        ], fn ($value) => $value !== null), $token);
        $items = [];
        foreach ($data['items'] ?? [] as $pin) {
            if (! preg_match('/^\d+$/D', (string) ($pin['id'] ?? ''))) {
                continue;
            }
            $media = $pin['media'] ?? [];
            $isVideo = str_contains($media['media_type'] ?? '', 'video');
            $picture = $media['images']['1200x']['url'] ?? $media['images']['600x']['url'] ?? $media['cover_image_url'] ?? null;
            $variants = [];
            foreach ($isVideo ? [$media['video_url'] ?? null] : array_column($media['images'] ?? [], 'url') as $url) {
                $url = Platform::Pinterest->mediaUrl($url);
                if ($url && (! $isVideo || str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.mp4'))) {
                    $labelForFile = $isVideo ? 'Original MP4' : 'Image '.(count($variants) + 1);
                    $variants[$url] = ['label' => $labelForFile, 'url' => $url];
                }
            }
            $items[] = [
                'remote_id' => $pin['id'], 'type' => $isVideo ? 'video' : 'photo', 'title' => ($pin['title'] ?? '') ?: 'Pinterest Pin',
                'permalink' => 'https://www.pinterest.com/pin/'.$pin['id'].'/', 'thumbnail' => $picture,
                'published_at' => $pin['created_at'] ?? null, 'variants' => array_values($variants),
            ];
        }
        $next = $data['bookmark'] ?? null;

        return new ImportPage($label, $items, $next ? ['page' => $next] : null);
    }
}
