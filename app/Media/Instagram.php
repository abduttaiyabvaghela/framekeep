<?php

namespace App\Media;

use Illuminate\Http\Client\ConnectionException;

class Instagram
{
    public function page(string $source): ImportPage
    {
        require_once __DIR__.'/InstagramEmbed.php';

        try {
            $data = InstagramEmbed\lookup($source);
        } catch (ConnectionException) {
            throw new ProviderException('Instagram could not be reached.', true);
        } catch (\RuntimeException $error) {
            throw new ProviderException($error->getMessage(), $error->getCode() >= 500);
        }
        if (str_contains($data['message'], 'rate limiting')) {
            throw new ProviderException($data['message'], true);
        }
        $items = array_map(fn (array $item) => [
            'remote_id' => $item['id'], 'type' => match ($item['kind']) {
                'reel', 'story' => $item['kind'],
                default => $item['video'] ? 'video' : 'photo',
            }, 'title' => $item['title'] ?: 'Instagram media', 'permalink' => $item['original'],
            'thumbnail' => $item['thumb'], 'published_at' => $item['time'] ? gmdate(DATE_ATOM, $item['time']) : null,
            'variants' => $item['asset'] ? [['label' => 'Original', 'url' => $item['asset']]] : [],
        ], $data['items']);

        return new ImportPage(str_starts_with($source, 'https://') ? 'Instagram media' : '@'.$source, $items, null, $data['message']);
    }
}
