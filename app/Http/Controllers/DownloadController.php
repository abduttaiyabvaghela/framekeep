<?php

namespace App\Http\Controllers;

use App\Media\Download;
use App\Media\Usage;
use App\Models\MediaItem;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DownloadController
{
    public function file(Request $request, int $item, Download $download, Usage $usage): StreamedResponse
    {
        $quality = $request->validate(['quality' => ['required', 'integer', 'min:0', 'max:99']])['quality'];
        $media = MediaItem::with('mediaImport')->whereHas('mediaImport', fn ($query) => $query->whereBelongsTo($request->user()))
            ->findOrFail($item);
        [$body, $type, $name, $length] = $download->open($media, $quality);
        try {
            $usage->claimDownload($request->user(), $media);
        } catch (Throwable $error) {
            $body->close();
            throw $error;
        }
        $headers = ['Content-Type' => $type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($length) {
            $headers['Content-Length'] = $length;
        }

        return response()->streamDownload(fn () => $download->copy($body, static function (string $part): void {
            echo $part;
        }, Download::MAX_FILE_BYTES), $name, $headers);
    }
}
