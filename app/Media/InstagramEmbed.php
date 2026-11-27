<?php

namespace AppMediaInstagramEmbed;

use DOMDocument;
use DOMXPath;
use RuntimeException;

const INSTAGRAM = 'https://www.instagram.com';
const HTML_LIMIT = 8_000_000;

function mediaUrl(mixed $value): ?string {
    if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
        return null;
    }
    $parts = parse_url($value);
    $host = strtolower($parts['host'] ?? '');
    return ($parts['scheme'] ?? '') === 'https'
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
        && preg_match('/^[a-z0-9.-]+\.(?:cdninstagram\.com|fbcdn\.net)$/D', $host)
        && preg_match('~^https://[a-z0-9.-]+/~i', $value) ? $value : null;
}

function fetchPublic(string $url, $destination, int $limit, int $timeout): array {
    $request = curl_init($url);
    $received = 0;
    curl_setopt_array($request, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Framekeep/1.0',
        CURLOPT_ENCODING => '',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use ($destination, $limit, &$received): int {
            $received += strlen($bytes);
            return $received > $limit ? 0 : (int) fwrite($destination, $bytes);
        },
    ]);
    $ok = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $type = strtolower(trim(explode(';', (string) curl_getinfo($request, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($request);
    if ($received > $limit) {
        throw new RuntimeException('The file exceeds the available download limit.', 413);
    }
    if (!$ok) {
        throw new RuntimeException('The server could not reach Instagram. Check its connection and try again.', 502);
    }
    rewind($destination);
    return [$status, $type];
}

function publicHtml(string $path): array {
    $buffer = fopen('php://temp', 'w+b');
    try {
        [$status] = fetchPublic(INSTAGRAM . $path, $buffer, HTML_LIMIT, 10);
        return [$status, $status === 200 ? stream_get_contents($buffer) : ''];
    } finally {
        fclose($buffer);
    }
}

function document(string $html): DOMDocument {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return $doc;
}

function readEmbedded(mixed $value, callable $visit, int $depth = 0): void {
    if ($depth > 65) {
        return;
    }
    if (is_string($value)) {
        $start = ltrim($value);
        if (str_starts_with($start, '{') || str_starts_with($start, '[')) {
            $decoded = json_decode($start, true, 512, JSON_BIGINT_AS_STRING);
            if (is_array($decoded)) {
                readEmbedded($decoded, $visit, $depth + 1);
            }
        }
        return;
    }
    if (is_array($value)) {
        $visit($value);
        foreach ($value as $child) {
            readEmbedded($child, $visit, $depth + 1);
        }
    }
}

function mediaFromPage(string $html, string $fallback, ?string $requestedCode, ?string $requestedKind): array {
    if ($html === '') {
        return [];
    }
    $doc = document($html);
    $items = [];
    $add = static function (
        array $node, string $code, string $kind, string $account, string $caption, int $position,
        ?array $parent = null
    ) use (&$items): void {
        $image = mediaUrl($node['display_url'] ?? $node['image_versions2']['candidates'][0]['url'] ?? $node['thumbnail_src'] ?? null);
        $video = mediaUrl($node['video_url'] ?? $node['video_versions'][0]['url'] ?? null);
        $isVideo = $video !== null || ($node['is_video'] ?? false) === true || ($node['media_type'] ?? null) === 2;
        if ($image === null && $video === null) {
            return;
        }
        $product = $node['product_type'] ?? $parent['product_type'] ?? '';
        $kind = $kind === 'post' && in_array($product, ['clips', 'reels'], true) ? 'reel' : $kind;
        $suffix = $position ? '-' . $position : '';
        $id = ($kind === 'story' ? 'story-' : '') . $code . $suffix;
        $path = match ($kind) {
            'reel' => "/reel/$code/",
            'story' => "/stories/$account/$code/",
            default => "/p/$code/",
        };
        $time = $node['taken_at_timestamp'] ?? $node['taken_at'] ?? $parent['taken_at_timestamp'] ?? $parent['taken_at'] ?? null;
        $asset = $isVideo ? $video : $image;
        $previous = $items[$id] ?? null;
        if ($previous && ($previous['asset'] || !$asset)) {
            return;
        }
        $items[$id] = [
            'id' => $id, 'account' => $account, 'kind' => $kind, 'video' => $isVideo,
            'title' => mb_substr(trim(strip_tags($caption)), 0, 220),
            'time' => is_numeric($time) && $time > 0 ? (int) $time : null,
            'asset' => $asset, 'thumb' => $image, 'original' => INSTAGRAM . $path,
        ];
    };
    $visit = static function (array $node) use ($add, $fallback, $requestedCode, $requestedKind): void {
        $storyId = $node['pk'] ?? $node['id'] ?? null;
        $code = $requestedKind === 'story' && (string) $storyId === $requestedCode
            ? $requestedCode : ($node['shortcode'] ?? $node['code'] ?? null);
        if (!is_string($code) || !preg_match('/^[A-Za-z0-9_-]{5,30}$/D', $code) || ($requestedCode && $code !== $requestedCode)) {
            return;
        }
        $account = $node['owner']['username'] ?? $node['user']['username'] ?? $fallback;
        $account = is_string($account) && preg_match('/^[A-Za-z0-9._]{1,30}$/D', $account) ? strtolower($account) : $fallback;
        if (!$requestedCode && strcasecmp($account, $fallback) !== 0) {
            return;
        }
        $caption = $node['edge_media_to_caption']['edges'][0]['node']['text'] ?? $node['caption']['text'] ?? $node['accessibility_caption'] ?? '';
        $caption = is_string($caption) ? $caption : '';
        $kind = $requestedKind ?? 'post';
        $children = $node['edge_sidecar_to_children']['edges'] ?? $node['carousel_media'] ?? [];
        if (is_array($children) && $children !== []) {
            foreach (array_values($children) as $index => $entry) {
                $child = $entry['node'] ?? $entry;
                if (is_array($child)) {
                    $add($child, $code, $kind, $account, $caption, $index + 1, $node);
                }
            }
        } else {
            $add($node, $code, $kind, $account, $caption, 0);
        }
    };
    foreach ($doc->getElementsByTagName('script') as $script) {
        $body = trim($script->textContent);
        if ($body === '' || (!str_contains($body, 'shortcode') && !str_contains($body, 'video_versions') && !str_contains($body, 'image_versions2') && !str_contains($body, 'display_url'))) {
            continue;
        }
        $json = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (is_array($json)) {
            readEmbedded($json, $visit);
        } elseif (preg_match_all('~"(?:[^"\\\\]++|\\\\.)*+"~s', $body, $strings)) {
            foreach ($strings[0] as $string) {
                if (str_contains($string, 'display_url') || str_contains($string, 'image_versions2') || str_contains($string, 'video_versions')) {
                    readEmbedded(json_decode($string, true), $visit);
                }
            }
        }
    }
    if ($requestedCode && $requestedKind !== 'story' && ($items === [] || (count($items) === 1 && empty(reset($items)['asset'])))) {
        $meta = [];
        foreach ($doc->getElementsByTagName('meta') as $tag) {
            $meta[$tag->getAttribute('property')] = $tag->getAttribute('content');
        }
        $image = mediaUrl($meta['og:image'] ?? null);
        $video = mediaUrl($meta['og:video:secure_url'] ?? $meta['og:video'] ?? null);
        if ($image || $video) {
            $account = $fallback;
            if (preg_match('/^([A-Za-z0-9._]{1,30}) on Instagram\b/i', $meta['og:title'] ?? '', $match)) {
                $account = strtolower($match[1]);
            }
            $add([
                'display_url' => $image, 'video_url' => $video,
                'is_video' => $requestedKind === 'reel' || !empty(reset($items)['video']) || ($meta['og:type'] ?? '') === 'video',
            ], $requestedCode, $requestedKind ?? 'post', $account, $meta['og:title'] ?? '', 0);
        }
    }
    if ($requestedCode && count($items) > 1 && isset($items[$requestedCode . '-1'])) {
        unset($items[$requestedCode]);
    }
    return array_values($items);
}

function parseSource(string $source): array {
    $source = trim($source);
    if (preg_match('~^(?:https?://)?(?:www\.|m\.)?instagram\.com/(.*)$~iD', $source, $match)) {
        $path = trim(preg_split('/[?#]/', $match[1], 2)[0], '/');
        if (preg_match('~^(?:[A-Za-z0-9._]{1,30}/)?(p|reel|reels|tv)/([A-Za-z0-9_-]{5,30})$~D', $path, $parts)) {
            $type = in_array($parts[1], ['reel', 'reels'], true) ? 'reel' : 'post';
            $segment = $parts[1] === 'tv' ? 'tv' : ($type === 'reel' ? 'reel' : 'p');
            return ["/$segment/{$parts[2]}/", 'Instagram', $parts[2], $type];
        }
        if (preg_match('~^stories/([A-Za-z0-9._]{1,30})/(\d{5,30})$~D', $path, $parts)) {
            $account = strtolower($parts[1]);
            return ["/stories/$account/{$parts[2]}/", $account, $parts[2], 'story'];
        }
        $source = $path;
    }
    $account = strtolower(preg_replace('/^@/', '', $source));
    if (preg_match('/^[a-z0-9._]{1,30}$/D', $account) && trim($account, '.') !== ''
        && !in_array($account, ['p', 'reel', 'reels', 'tv', 'stories', 'accounts', 'explore', 'direct', 'about'], true)) {
        return ["/$account/", $account, null, null];
    }
    throw new RuntimeException('Enter an Instagram username or a direct profile, post, reel, or story link.', 400);
}

function lookup(string $source): array {
    [$path, $fallback, $code, $kind] = parseSource($source);
    $paths = $kind === 'story' ? [$path] : [$path . 'embed/', $path];
    $items = [];
    $status = 0;
    foreach ($paths as $candidate) {
        [$status, $html] = publicHtml($candidate);
        if (in_array($status, [401, 403, 429], true)) {
            break;
        }
        $found = mediaFromPage($html, $fallback, $code, $kind);
        foreach ($found as $item) {
            if (!isset($items[$item['id']]) || $item['asset']) {
                $items[$item['id']] = $item;
            }
        }
        if ($items && (!$code || array_filter($items, static fn(array $item): bool => $item['asset'] !== null))) {
            break;
        }
    }
    if ($items) {
        if ($code === null) {
            // Profile embeds generally expose a still image for each video.
            $checked = [];
            foreach ($items as $id => $item) {
                if (!$item['video'] || $item['asset'] || count($checked) >= 6) {
                    continue;
                }
                $original = $item['original'];
                if (!isset($checked[$original])) {
                    try {
                        $checked[$original] = array_column(lookup($original)['items'], null, 'id');
                    } catch (RuntimeException) {
                        $checked[$original] = [];
                    }
                }
                $resolved = $checked[$original][$id] ?? null;
                if ($resolved) {
                    $items[$id] = array_replace($item, [
                        'kind' => $resolved['kind'], 'asset' => $resolved['asset'], 'original' => $resolved['original'],
                    ]);
                }
            }
        }
        if ($code && count($items) > 1 && isset($items[$code . '-1'])) {
            unset($items[$code]);
        }
        $message = !$code
            ? 'Recent public items only. Complete history and active stories require another source.'
            : (array_filter($items, static fn(array $item): bool => $item['asset'] === null) ? 'Instagram supplied a preview without a downloadable original.' : '');
        return ['items' => array_values($items), 'message' => $message];
    }
    $message = match ($status) {
        429 => 'Instagram is rate limiting public requests. Try again later.',
        404 => 'Instagram did not return this account or link. Check the spelling and visibility.',
        0, 401, 403 => 'Instagram restricted this request. Try a public post link.',
        default => $kind === 'story' ? 'Instagram did not expose this story publicly.'
            : 'Instagram exposed no downloadable media for this source. Try a public post link.',
    };
    return ['items' => [], 'message' => $message];
}
