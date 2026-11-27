<?php

namespace App\Media;

class YouTube
{
    public function __construct(private ProviderClient $http) {}

    public function page(string $source, ?array $cursor): ImportPage
    {
        $uploads = $cursor['uploads'] ?? null;
        $label = $cursor['label'] ?? $source;
        if (! $uploads) {
            $channel = $this->channel($source);
            $uploads = $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null;
            $label = $channel['snippet']['title'] ?? $source;
        }
        if (! $uploads) {
            throw new ProviderException('This channel did not expose an uploads playlist.');
        }
        $data = $this->get('playlistItems', [
            'part' => 'snippet,contentDetails', 'playlistId' => $uploads, 'maxResults' => 50,
            'pageToken' => $cursor['page'] ?? null,
        ]);
        $items = [];
        foreach ($data['items'] ?? [] as $entry) {
            $video = $entry['contentDetails']['videoId'] ?? '';
            $info = $entry['snippet'] ?? [];
            if (! preg_match('/^[A-Za-z0-9_-]{11}$/D', $video) || in_array($info['title'] ?? '', ['Private video', 'Deleted video'], true)) {
                continue;
            }
            $items[] = [
                'remote_id' => $video, 'type' => 'video', 'title' => $info['title'] ?? 'YouTube video',
                'permalink' => 'https://www.youtube.com/watch?v='.$video,
                'thumbnail' => $info['thumbnails']['medium']['url'] ?? $info['thumbnails']['default']['url'] ?? null,
                'published_at' => $entry['contentDetails']['videoPublishedAt'] ?? $info['publishedAt'] ?? null,
                'variants' => [],
            ];
        }
        $next = $data['nextPageToken'] ?? null;

        return new ImportPage($label, $items, $next ? ['uploads' => $uploads, 'label' => $label, 'page' => $next] : null,
            'YouTube provides video metadata and watch links.');
    }

    private function channel(string $source): array
    {
        $params = ['part' => 'snippet,contentDetails'];
        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $source)) {
            $params['id'] = $source;
        } elseif (str_starts_with($source, '@')) {
            $params['forHandle'] = $source;
        } else {
            $match = preg_match('/^[\p{L}\p{N}_.-]+$/uD', $source)
                ? $this->get('channels', $params + ['forHandle' => $source])
                : [];
            if (isset($match['items'][0])) {
                return $match['items'][0];
            }
            $results = $this->get('search', ['part' => 'snippet', 'type' => 'channel', 'q' => $source, 'maxResults' => 15]);
            $matches = array_values(array_filter($results['items'] ?? [], fn (array $row) =>
                mb_strtolower(html_entity_decode($row['snippet']['title'] ?? '')) === mb_strtolower($source)));
            if (count($matches) !== 1 || ! isset($matches[0]['id']['channelId'])) {
                throw new ProviderException('Use the channel’s @handle or ID to identify it unambiguously.');
            }
            $params['id'] = $matches[0]['id']['channelId'];
        }

        return $this->get('channels', $params)['items'][0]
            ?? throw new ProviderException('YouTube did not return that channel.');
    }

    private function get(string $resource, array $params): array
    {
        return $this->http->json('https://www.googleapis.com/youtube/v3/'.$resource, array_filter($params + [
            'key' => config('framekeep.youtube_key'),
        ], fn ($value) => $value !== null));
    }
}
