<?php

namespace App\Media;

enum Platform: string
{
    case Instagram = 'instagram';
    case InstagramPro = 'instagram_pro';
    case YouTube = 'youtube';
    case X = 'x';
    case Pinterest = 'pinterest';

    public function ready(): bool
    {
        return match ($this) {
            self::Instagram => true,
            self::InstagramPro => str_starts_with(config('app.url'), 'https://')
                && filled(config('framekeep.instagram.app_id')) && filled(config('framekeep.instagram.secret'))
                && preg_match('/^v\d+\.\d+$/D', config('framekeep.instagram.version')) === 1,
            self::YouTube => filled(config('framekeep.youtube_key')),
            self::X => filled(config('framekeep.x_token')),
            self::Pinterest => filled(config('framekeep.pinterest_token')) && count(config('framekeep.pinterest_boards')) > 0,
        };
    }

    public function source(string $source): string
    {
        $source = trim($source);
        if ($this === self::X && preg_match('~^https://(?:www\.)?(?:x\.com|twitter\.com)/([A-Za-z0-9_]{1,15})/?(?:[?#].*)?$~iD', $source, $match)) {
            $source = $match[1];
        }
        if ($this === self::YouTube && preg_match('~^https://(?:www\.|m\.)?youtube\.com/(@[^/#?]+|channel/UC[A-Za-z0-9_-]{22})(?:/videos|/shorts|/streams)?/?(?:[?#].*)?$~iDu', $source, $match)) {
            $source = rawurldecode(str_replace('channel/', '', $match[1]));
        }
        $valid = match ($this) {
            self::Instagram => $this->instagramSource($source) !== null,
            self::InstagramPro => in_array($source, ['media', 'stories'], true),
            self::YouTube => $source !== '' && mb_strlen($source) <= 120 && !str_contains($source, '://'),
            self::X => preg_match('/^@?[A-Za-z0-9_]{1,15}$/D', $source),
            self::Pinterest => preg_match('/^\d{1,20}$/D', $source)
                && in_array($source, config('framekeep.pinterest_boards'), true),
        };
        if (! $valid) {
            throw \Illuminate\Validation\ValidationException::withMessages(['source' => match ($this) {
                self::Instagram => 'Enter a public Instagram username, post, reel, or story URL.',
                self::InstagramPro => 'Choose posts and reels or active stories.',
                self::X => 'Enter a valid public username or profile link.',
                self::YouTube => 'Enter a channel name, channel ID, @handle, or channel link.',
                self::Pinterest => 'Enter an approved public board ID.',
            }]);
        }

        return match ($this) {
            self::Instagram => $this->instagramSource($source),
            self::X => strtolower(ltrim($source, '@')),
            default => $source,
        };
    }

    private function instagramSource(string $source): ?string
    {
        require_once __DIR__.'/InstagramEmbed.php';
        try {
            [$path, $account, $code] = InstagramEmbed\parseSource($source);

            return $code ? 'https://www.instagram.com'.$path : $account;
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function mediaUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $allowed = match ($this) {
            self::Instagram, self::InstagramPro => (bool) preg_match('/^[a-z0-9.-]+\.(?:cdninstagram\.com|fbcdn\.net)$/D', $host),
            self::X => in_array($host, ['pbs.twimg.com', 'video.twimg.com'], true),
            self::Pinterest => in_array($host, ['i.pinimg.com', 'v.pinimg.com'], true),
            self::YouTube => in_array($host, ['i.ytimg.com', 'img.youtube.com'], true),
        };

        return $allowed ? $url : null;
    }
}
