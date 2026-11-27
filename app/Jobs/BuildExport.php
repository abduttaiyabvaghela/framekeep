<?php

namespace App\Jobs;

use App\Media\Download;
use App\Models\MediaExport;
use App\Models\MediaItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use ZipArchive;

class BuildExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public int $backoff = 60;

    public function __construct(public int $exportId)
    {
        $this->onQueue('exports');
    }

    public function handle(Download $download): void
    {
        $lock = Cache::lock('export-'.$this->exportId, 660);
        if (! $lock->get()) {
            return;
        }
        $paths = [];
        $archive = new ZipArchive;
        $opened = false;
        try {
            $export = MediaExport::find($this->exportId);
            if (! $export || ! in_array($export->status, ['queued', 'running'], true)) {
                return;
            }
            $export->update(['status' => 'running']);
            $zip = $paths[] = $this->tempPath();
            if ($archive->open($zip, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not initialize the ZIP file.');
            }
            $opened = true;
            $items = MediaItem::with('mediaImport')->whereIn('id', array_column($export->selection, 'id'))
                ->whereHas('mediaImport', fn ($query) => $query->where('user_id', $export->user_id))->get()->keyBy('id');
            $remaining = config('framekeep.export_max_mib') * 1024 * 1024;
            $skipped = [];
            $added = 0;
            foreach ($export->selection as $choice) {
                $item = $items->get($choice['id']);
                if (! $item || $remaining <= 0) {
                    $skipped[] = $choice['id'];
                    continue;
                }
                try {
                    [$body, , $name, $length] = $download->open($item, $choice['quality']);
                } catch (HttpExceptionInterface) {
                    $skipped[] = $choice['id'];
                    continue;
                }
                if ($length && (int) $length > $remaining) {
                    $body->close();
                    $skipped[] = $choice['id'];
                    continue;
                }
                $file = $paths[] = $this->tempPath();
                $stream = fopen($file, 'wb');
                if (! $stream) {
                    $body->close();
                    throw new \RuntimeException('Could not open a temporary media file.');
                }
                try {
                    $written = $download->copy($body, static function (string $part) use ($stream): void {
                        if (fwrite($stream, $part) !== strlen($part)) {
                            throw new \RuntimeException('The temporary media file could not be written.');
                        }
                    }, min($remaining, Download::MAX_FILE_BYTES));
                } catch (\RuntimeException) {
                    $skipped[] = $choice['id'];
                    fclose($stream);
                    $stream = null;
                    @unlink($file);
                    continue;
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (! $archive->addFile($file, $name)) {
                    throw new \RuntimeException('Could not add a file to the ZIP.');
                }
                $archive->setCompressionName($name, ZipArchive::CM_STORE);
                $remaining -= $written;
                $added++;
            }
            if (! $added) {
                $export->update(['status' => 'failed', 'message' => 'None of the selected files were available.', 'expires_at' => now()->addDay()]);

                return;
            }
            if ($skipped) {
                $archive->addFromString('skipped.txt', 'Unavailable or over the size limit: '.implode(', ', $skipped));
            }
            $closed = $archive->close();
            $opened = false;
            if (! $closed) {
                throw new \RuntimeException('Could not finalize the ZIP file.');
            }
            $disk = config('framekeep.export_disk');
            $path = 'exports/'.bin2hex(random_bytes(20)).'.zip';
            $handle = fopen($zip, 'rb');
            try {
                if (! $handle || ! Storage::disk($disk)->put($path, $handle)) {
                    throw new \RuntimeException('Could not store the ZIP file.');
                }
            } finally {
                if ($handle) {
                    fclose($handle);
                }
            }
            $export->update(['status' => 'completed', 'disk' => $disk, 'path' => $path, 'expires_at' => now()->addDay(),
                'message' => $skipped ? count($skipped).' file(s) could not be included.' : null]);
        } finally {
            if ($opened) {
                $archive->close();
            }
            foreach ($paths as $path) {
                @unlink($path);
            }
            $lock->release();
        }
    }

    public function failed(): void
    {
        MediaExport::whereKey($this->exportId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'message' => 'The ZIP export could not finish.', 'expires_at' => now()->addDay(),
        ]);
    }

    private function tempPath(): string
    {
        return tempnam(sys_get_temp_dir(), 'framekeep-') ?: throw new \RuntimeException('Temporary storage is unavailable.');
    }
}
