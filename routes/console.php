<?php

use App\Jobs\ProcessImport;
use App\Jobs\BuildExport;
use App\Models\MediaExport;
use App\Models\MediaImport;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Schedule::call(function (): void {
    MediaImport::whereIn('status', ['queued', 'running'])
        ->where('updated_at', '<', now()->subMinutes(10))
        ->orderBy('updated_at')->limit(100)->get(['id'])
        ->each(fn (MediaImport $import) => ProcessImport::dispatch($import->id));
})->name('recover-interrupted-imports')->everyFiveMinutes()->withoutOverlapping();

Schedule::call(function (): void {
    MediaExport::whereIn('status', ['queued', 'running'])
        ->where('updated_at', '<', now()->subMinutes(16))
        ->orderBy('updated_at')->limit(50)->get(['id'])
        ->each(fn (MediaExport $export) => BuildExport::dispatch($export->id));
})->name('recover-interrupted-exports')->everyFiveMinutes()->withoutOverlapping();

Schedule::call(function (): void {
    MediaExport::whereNotNull('expires_at')->where('expires_at', '<=', now())
        ->chunkById(100, function ($exports): void {
            foreach ($exports as $export) {
                if ($export->path) {
                    Storage::disk($export->disk)->delete($export->path);
                }
                $export->delete();
            }
        });
})->name('purge-expired-exports')->hourly()->withoutOverlapping();
