<?php

namespace App\Jobs;

use App\Media\Instagram;
use App\Media\InstagramProfessional;
use App\Media\Pinterest;
use App\Media\Platform;
use App\Media\ProviderException;
use App\Media\X;
use App\Media\YouTube;
use App\Models\MediaImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;
    public int $timeout = 170;
    public array $backoff = [15, 60, 120];

    public function __construct(public int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $lock = Cache::lock('process-import:'.$this->importId, 200);
        if (! $lock->get()) {
            return;
        }
        $next = false;
        try {
            $import = MediaImport::find($this->importId);
            if (! $import || ! in_array($import->status, ['queued', 'running'], true)) {
                return;
            }
            $import->update(['status' => 'running']);
            $platform = Platform::from($import->platform);
            $cursor = $import->cursor ? json_decode($import->cursor, true, flags: JSON_THROW_ON_ERROR) : null;
            try {
                $page = match ($platform) {
                    Platform::Instagram => app(Instagram::class)->page($import->source),
                    Platform::InstagramPro => app(InstagramProfessional::class)->page($import->user_id, $import->source, $cursor),
                    Platform::YouTube => app(YouTube::class)->page($import->source, $cursor),
                    Platform::X => app(X::class)->page($import->source, $cursor),
                    Platform::Pinterest => app(Pinterest::class)->page($import->source, $cursor),
                };
            } catch (ProviderException $error) {
                if ($error->retryable) {
                    throw $error;
                }
                $import->update(['status' => 'failed', 'message' => mb_substr($error->getMessage(), 0, 300)]);

                return;
            }
            $next = $page->cursor !== null && $page->cursor !== $cursor && $import->pages < 1999;
            DB::transaction(function () use ($import, $platform, $page, $next): void {
                $rows = [];
                foreach ($page->items as $item) {
                    $variants = [];
                    foreach ($item['variants'] as $variant) {
                        $url = $platform->mediaUrl($variant['url']);
                        if ($url !== null) {
                            $variants[] = ['url' => $url, 'label' => mb_substr($variant['label'], 0, 80)];
                        }
                    }
                    $published = null;
                    if ($item['published_at']) {
                        try {
                            $date = Carbon::parse($item['published_at'])->utc();
                            if ($date->year >= 1971 && $date->year <= 2037) {
                                $published = $date->toDateTimeString();
                            }
                        } catch (\InvalidArgumentException) {
                            // Some providers omit a usable date.
                        }
                    }
                    $rows[$item['remote_id']] = [
                        'remote_id' => $item['remote_id'],
                        'type' => $item['type'], 'title' => mb_substr($item['title'], 0, 255),
                        'permalink' => $item['permalink'], 'thumbnail' => $platform->mediaUrl($item['thumbnail']),
                        'published_at' => $published, 'variants' => json_encode($variants, JSON_THROW_ON_ERROR),
                    ];
                }
                foreach (array_chunk(array_values($rows), 50) as $batch) {
                    $import->items()->upsert($batch, ['media_import_id', 'remote_id'],
                        ['type', 'title', 'permalink', 'thumbnail', 'published_at', 'variants']);
                }
                $import->update(['status' => $next ? 'running' : 'completed', 'label' => mb_substr($page->label, 0, 255),
                    'pages' => $import->pages + 1, 'cursor' => $next ? json_encode($page->cursor, JSON_THROW_ON_ERROR) : null,
                    'message' => $next || ! $page->cursor ? $page->message : 'The provider pagination limit was reached.']);
            }, 3);
        } finally {
            $lock->release();
        }
        if ($next) {
            self::dispatch($this->importId);
        }
    }

    public function failed(): void
    {
        MediaImport::whereKey($this->importId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'message' => 'The source is temporarily unavailable. Try importing it again.']);
    }
}
