<?php

namespace App\Http\Controllers;

use App\Jobs\BuildExport;
use App\Models\MediaExport;
use App\Models\MediaImport;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ExportController
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasFullAccess(), 403, 'ZIP exports require full access.');
        $max = config('framekeep.export_max_files');
        $body = $request->validate([
            'import_id' => ['required_without:items', 'prohibits:items', 'integer', 'min:1'],
            'items' => ['required_without:import_id', 'prohibits:import_id', 'array', 'min:1', 'max:'.$max],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quality' => ['required', 'integer', 'min:0', 'max:99'],
        ]);
        $user = $request->user();
        if (isset($body['import_id'])) {
            $import = MediaImport::whereBelongsTo($user)->findOrFail($body['import_id']);
            $selection = $import->items()->whereJsonLength('variants', '>', 0)->orderBy('id')->limit($max + 1)->get(['id'])
                ->map(fn ($item) => ['id' => $item->id, 'quality' => 0])->all();
            abort_if(count($selection) > $max, 422, "Choose up to $max items for each ZIP.");
        } else {
            $selection = array_values($body['items']);
            $ids = array_column($selection, 'id');
            $owned = MediaItem::whereIn('id', $ids)->whereHas('mediaImport', fn ($query) => $query->whereBelongsTo($user))
                ->get()->keyBy('id');
            foreach ($selection as $choice) {
                abort_unless(isset($owned[$choice['id']]->variants[$choice['quality']]), 422, 'A selected file or quality is unavailable.');
            }
        }
        abort_if($selection === [], 422, 'This import does not have downloadable files.');
        $lock = Cache::lock('new-export:'.$user->id, 10);
        abort_unless($lock->get(), 409, 'A ZIP request is already being submitted.');
        try {
            abort_if(MediaExport::whereBelongsTo($user)->whereIn('status', ['queued', 'running'])->count() >= 2, 429,
                'You already have two ZIP exports in progress.');
            $export = MediaExport::create(['user_id' => $user->id, 'selection' => $selection]);
            BuildExport::dispatch($export->id);
        } finally {
            $lock->release();
        }

        return response()->json($export->refresh(), 202);
    }

    public function show(Request $request, int $export): JsonResponse
    {
        return response()->json($this->owned($request, $export));
    }

    public function file(Request $request, int $export): Response
    {
        $row = $this->owned($request, $export);
        abort_unless($row->status === 'completed' && $row->expires_at?->isFuture(), 404, 'This export is unavailable.');
        $disk = Storage::disk($row->disk);
        if ($row->disk === 's3') {
            return redirect()->away($disk->temporaryUrl($row->path, now()->addMinutes(5), [
                'ResponseContentDisposition' => 'attachment; filename="framekeep-'.$export.'.zip"',
            ]));
        }

        return $disk->download($row->path, 'framekeep-'.$export.'.zip', ['Cache-Control' => 'private, no-store']);
    }

    private function owned(Request $request, int $id): MediaExport
    {
        return MediaExport::whereBelongsTo($request->user())->findOrFail($id);
    }
}
