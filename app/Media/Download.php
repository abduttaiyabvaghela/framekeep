<?php

namespace App\Media;

use App\Models\MediaItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

class Download
{
    public const MAX_FILE_BYTES = 300 * 1024 * 1024;

    public function open(MediaItem $item, int $quality): array
    {
        $platform = Platform::from($item->mediaImport->platform);
        $variant = $item->variants[$quality] ?? null;
        $url = $platform->mediaUrl($variant['url'] ?? null);
        abort_unless($url && $platform !== Platform::YouTube, 404, 'This quality is unavailable.');
        try {
            $response = Http::withoutRedirecting()->withOptions(['stream' => true, 'read_timeout' => 15])
                ->connectTimeout(4)->timeout(45)->get($url);
        } catch (ConnectionException) {
            abort(502, 'The original provider could not be reached.');
        }
        $body = $response->toPsrResponse()->getBody();
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $extensions = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'image/avif' => 'avif', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
        ];
        $length = $response->header('Content-Length');
        if (! $response->ok() || ! isset($extensions[$type]) || ($length && (! ctype_digit($length) || (int) $length > self::MAX_FILE_BYTES))) {
            $body->close();
            abort(502, 'This file could not be downloaded from the source. Try refreshing the import.');
        }

        return [$body, $type, 'framekeep-'.$item->id.'-'.$quality.'.'.$extensions[$type], $length];
    }

    public function copy(StreamInterface $stream, callable $write, int $max): int
    {
        $bytes = 0;
        try {
            while (! $stream->eof()) {
                $part = $stream->read(65_536);
                if ($part === '' && ! $stream->eof()) {
                    throw new \RuntimeException('The upstream media stream ended prematurely.');
                }
                $bytes += strlen($part);
                if ($bytes > $max) {
                    throw new \RuntimeException('The download exceeds the size limit.');
                }
                $write($part);
            }
        } finally {
            $stream->close();
        }

        return $bytes;
    }
}
