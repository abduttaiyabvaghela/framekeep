<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImport;
use App\Media\Platform;
use App\Media\Usage;
use App\Models\InstagramConnection;
use App\Models\MediaImport;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ImportController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(MediaImport::whereBelongsTo($request->user())->orderByDesc('id')->cursorPaginate(25));
    }

    public function store(Request $request, Usage $usage): JsonResponse
    {
        $data = $request->validate(['platform' => ['required', Rule::enum(Platform::class)], 'source' => ['required', 'string', 'max:160']]);
        $platform = Platform::from($data['platform']);
        abort_unless($platform->ready(), 503, 'This platform is not configured.');
        $source = $platform->source($data['source']);
        $user = $request->user();
        $key = 'new-import:'.$user->id.':'.hash('sha256', $platform->value.':'.$source);
        $lock = Cache::lock($key, 10);
        abort_unless($lock->get(), 409, 'That source is already being submitted.');
        try {
            $import = DB::transaction(function () use ($user, $usage, $platform, $source): MediaImport {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $quotaSource = $platform->value.':'.$source;
                if ($platform === Platform::InstagramPro) {
                    $connection = InstagramConnection::whereBelongsTo($user)->where('expires_at', '>', now())->first();
                    abort_unless($connection, 403, 'Connect your Instagram professional account first.');
                    $quotaSource = 'instagram:'.strtolower($connection->username);
                    $source = $connection->account.':'.$source;
                }
                $row = MediaImport::firstOrNew(['user_id' => $user->id, 'platform' => $platform->value, 'source' => $source]);
                if ($row->exists && in_array($row->status, ['queued', 'running'], true)) {
                    return $row;
                }
                $usage->claimSource($user, $quotaSource);
                abort_if(MediaImport::whereBelongsTo($user)->whereIn('status', ['queued', 'running'])->count() >= 15, 429,
                    'Wait for active imports to finish before adding more.');
                $row->fill(['status' => 'queued', 'cursor' => null, 'pages' => 0, 'message' => null])->save();
                ProcessImport::dispatch($row->id)->afterCommit();

                return $row;
            });
        } finally {
            $lock->release();
        }

        return response()->json($import, 202);
    }

    public function show(Request $request, int $import): JsonResponse
    {
        return response()->json($this->owned($request, $import));
    }

    public function items(Request $request, int $import): JsonResponse
    {
        $query = $this->owned($request, $import)->items()->orderBy('id');
        $options = $request->validate(['type' => ['nullable', 'in:photo,video,reel,story']]);
        if (isset($options['type'])) {
            $query->where('type', $options['type']);
        }
        $items = $query->cursorPaginate(30)->through(fn (MediaItem $item) => [
            'id' => $item->id, 'import_id' => $item->media_import_id, 'type' => $item->type, 'title' => $item->title,
            'permalink' => $item->permalink, 'thumbnail' => $item->thumbnail,
            'published_at' => $item->published_at?->toAtomString(),
            'qualities' => array_map(fn (array $variant, int $index) => ['id' => $index, 'label' => $variant['label']],
                $item->variants, array_keys($item->variants)),
        ]);

        return response()->json($items);
    }

    private function owned(Request $request, int $id): MediaImport
    {
        return MediaImport::whereBelongsTo($request->user())->findOrFail($id);
    }
}
