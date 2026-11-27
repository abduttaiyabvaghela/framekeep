<?php

namespace Tests\Feature;

use App\Models\MediaImport;
use App\Models\InstagramConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_sign_in_and_annual_pricing_with_payments_disabled(): void
    {
        $password = 'legacy-test-pass';
        $user = User::create(['email' => 'admin@example.test', 'password' => password_hash($password, PASSWORD_BCRYPT)]);
        config(['framekeep.admin_ids' => [$user->id]]);

        $this->get('/')->assertOk()->assertSee('Framekeep')->assertSee('library.js');
        $this->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('pro.interval', 'year')
            ->assertJsonPath('pro.amount_minor', 2000)->assertJsonPath('checkout_enabled', false);
        $this->postJson('/api/v1/session/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()->assertJsonPath('user.full_access', true);
        $this->withHeader('Referer', config('app.url').'/')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('admin', true);
        $this->postJson('/api/v1/checkout', ['plan' => 'pro-annual'])->assertStatus(503);
    }

    public function test_mobile_tokens_are_stored_hashed_and_can_be_revoked(): void
    {
        $token = $this->postJson('/api/v1/tokens/register', [
            'device' => 'phone', 'email' => 'mobile@example.test', 'password' => 'mobile-test-pass',
        ])->assertCreated()->json('token');
        [, $secret] = explode('|', $token);
        $this->assertDatabaseHas('personal_access_tokens', ['token' => hash('sha256', $secret)]);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('full_access', false);
        $this->withToken($token)->deleteJson('/api/v1/tokens/current')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_channel_name_paginates_uploads_and_free_accounts_have_one_daily_source(): void
    {
        config(['framekeep.youtube_key' => 'test-youtube-key']);
        $this->actingAs($this->user('free'));
        Http::preventStrayRequests();
        Http::fake([
            'www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [
                ['id' => ['channelId' => 'UC'.str_repeat('a', 22)], 'snippet' => ['title' => 'Example Channel']],
            ]]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'snippet' => ['title' => 'Example Channel'], 'contentDetails' => ['relatedPlaylists' => ['uploads' => 'uploads']],
            ]]]),
            'www.googleapis.com/youtube/v3/playlistItems*' => fn (Request $request) => Http::response(
                str_contains($request->url(), 'pageToken=next') ? ['items' => [$this->video('bbbbbbbbbbb')]]
                    : ['items' => [$this->video('aaaaaaaaaaa')], 'nextPageToken' => 'next']),
        ]);
        $id = $this->postJson('/api/v1/imports', ['platform' => 'youtube', 'source' => 'Example Channel'])
            ->assertAccepted()->json('id');
        $this->getJson("/api/v1/imports/$id")->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('pages', 2);
        $this->getJson("/api/v1/imports/$id/items")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.qualities', [])->assertJsonPath('data.1.permalink', 'https://www.youtube.com/watch?v=bbbbbbbbbbb');
        $this->postJson('/api/v1/imports', ['platform' => 'youtube', 'source' => '@another'])
            ->assertForbidden();
        $this->postJson('/api/v1/exports', ['import_id' => $id])->assertForbidden();
        $this->getJson('/api/v1/imports?platform=instagram')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/imports?platform=youtube')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($this->user('other'))->getJson("/api/v1/imports/$id/items")->assertNotFound();
    }

    public function test_x_quality_selection_and_downloadable_zip_for_admin(): void
    {
        Storage::fake('local');
        $admin = $this->user('admin');
        $this->actingAs($admin);
        config(['framekeep.x_token' => 'test-x-token', 'framekeep.admin_ids' => [$admin->id]]);
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/users/by/username/*' => Http::response(['data' => ['id' => '123']]),
            'api.x.com/2/users/123/tweets*' => Http::response([
                'data' => [['id' => '1', 'text' => 'Video', 'attachments' => ['media_keys' => ['media']]]],
                'includes' => ['media' => [['media_key' => 'media', 'type' => 'video', 'variants' => [
                    ['url' => 'https://video.twimg.com/test/640x360/test.mp4', 'content_type' => 'video/mp4'],
                    ['url' => 'https://video.twimg.com/test/1280x720/test.mp4', 'content_type' => 'video/mp4'],
                    ['url' => 'https://elsewhere.test/forbidden.mp4', 'content_type' => 'video/mp4'],
                ]]]],
            ]),
            'video.twimg.com/*' => fn () => Http::response('example-video', 200, ['Content-Type' => 'video/mp4', 'Content-Length' => '13']),
        ]);
        $import = $this->postJson('/api/v1/imports', ['platform' => 'x', 'source' => 'https://x.com/Example'])->assertAccepted()
            ->assertJsonPath('source', 'example')->json('id');
        $item = $this->getJson("/api/v1/imports/$import/items")->assertOk()->assertJsonCount(2, 'data.0.qualities')
            ->assertJsonPath('data.0.qualities.1.label', '720p')->json('data.0.id');
        $this->postJson("/api/v1/media/$item/download", ['quality' => 1])->assertOk()->assertStreamedContent('example-video');
        $export = $this->postJson('/api/v1/exports', ['items' => [['id' => $item, 'quality' => 1]]])
            ->assertAccepted()->assertJsonPath('status', 'completed')->json('id');
        $record = \App\Models\MediaExport::findOrFail($export);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($record->path)));
        $this->assertSame('example-video', $zip->getFromName("framekeep-$item-1.mp4"));
        $zip->close();
        $this->get("/api/v1/exports/$export/download")->assertOk()->assertDownload("framekeep-$export.zip");
    }

    public function test_free_download_cap_counts_unique_items(): void
    {
        config(['framekeep.free_downloads' => 1]);
        $user = $this->user('limited');
        $this->actingAs($user);
        $import = MediaImport::create(['user_id' => $user->id, 'platform' => 'x', 'source' => 'example', 'status' => 'completed']);
        Http::fake(['pbs.twimg.com/*' => fn () => Http::response('image', 200, ['Content-Type' => 'image/jpeg'])]);
        $photo = ['type' => 'photo', 'title' => 'Photo', 'permalink' => 'https://x.com/example/status/1',
            'variants' => [['label' => 'Original', 'url' => 'https://pbs.twimg.com/example.jpg']]];
        $first = $import->items()->create(['remote_id' => '1'] + $photo)->id;
        $second = $import->items()->create(['remote_id' => '2'] + $photo)->id;
        $this->postJson("/api/v1/media/$first/download", ['quality' => 0])->assertOk()->assertStreamedContent('image');
        $this->postJson("/api/v1/media/$first/download", ['quality' => 0])->assertOk()->assertStreamedContent('image');
        $this->postJson("/api/v1/media/$second/download", ['quality' => 0])->assertForbidden();
    }

    public function test_connected_instagram_stores_an_encrypted_token_and_preserves_imports_on_disconnect(): void
    {
        config(['app.url' => 'https://framekeep.example.test', 'framekeep.instagram.app_id' => 'test-id',
            'framekeep.instagram.secret' => 'test-secret']);
        $user = $this->user('instagram');
        $this->actingAs($user);
        Http::preventStrayRequests();
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short-test-token']),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-test-token', 'expires_in' => 5000]),
            'graph.instagram.com/*/me*' => Http::response(['user_id' => '12345', 'username' => 'creator']),
            'graph.instagram.com/*/12345/stories*' => Http::response(['data' => [[
                'id' => '789', 'media_type' => 'IMAGE', 'media_url' => 'https://photo.cdninstagram.com/story.jpg',
            ]]]),
        ]);
        $location = $this->postJson('/api/v1/session/instagram/connect')->assertOk()->json('url');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->get('/instagram/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test']))
            ->assertStatus(303);
        $this->getJson('/api/v1/instagram/connection')->assertOk()->assertJsonPath('username', 'creator')->assertJsonMissingPath('token');
        $row = InstagramConnection::firstOrFail();
        $this->assertNotSame('long-test-token', $row->getRawOriginal('token'));
        $this->assertSame('long-test-token', $row->token);
        $id = $this->postJson('/api/v1/imports', ['platform' => 'instagram_pro', 'source' => 'stories'])
            ->assertAccepted()->json('id');
        $this->getJson("/api/v1/imports/$id/items")->assertOk()->assertJsonPath('data.0.type', 'story');
        $this->getJson('/api/v1/imports?platform=instagram')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->deleteJson('/api/v1/session/instagram')->assertOk();
        $this->assertNull($row->refresh()->token);
        $this->assertDatabaseHas('media_imports', ['id' => $id]);
        $this->postJson('/api/v1/imports', ['platform' => 'instagram_pro', 'source' => 'stories'])->assertForbidden();
    }

    public function test_pinterest_imports_only_approved_public_boards_and_pro_can_use_multiple_sources(): void
    {
        config(['framekeep.pinterest_token' => 'test-pinterest', 'framekeep.pinterest_boards' => ['101', '202']]);
        $user = $this->user('pro');
        \App\Models\ProSubscription::create(['user_id' => $user->id, 'paid_until' => now()->addYear()]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        Http::fake([
            'api.pinterest.com/v5/boards/101' => Http::response(['privacy' => 'PUBLIC', 'name' => 'Outdoors']),
            'api.pinterest.com/v5/boards/101/pins*' => Http::response(['items' => [[
                'id' => '321', 'title' => 'Forest', 'media' => ['media_type' => 'image', 'images' => [
                    '1200x' => ['url' => 'https://i.pinimg.com/forest.jpg'],
                ]],
            ]]]),
            'api.pinterest.com/v5/boards/202' => Http::response(['privacy' => 'SECRET', 'name' => 'Private']),
        ]);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('pro', true)->assertJsonPath('admin', false);
        $this->postJson('/api/v1/imports', ['platform' => 'pinterest', 'source' => '303'])->assertUnprocessable();
        $public = $this->postJson('/api/v1/imports', ['platform' => 'pinterest', 'source' => '101'])->assertAccepted()->json('id');
        $this->getJson("/api/v1/imports/$public/items")->assertOk()->assertJsonPath('data.0.qualities.0.id', 0);
        $private = $this->postJson('/api/v1/imports', ['platform' => 'pinterest', 'source' => '202'])->assertAccepted()->json('id');
        $this->getJson("/api/v1/imports/$private")->assertOk()->assertJsonPath('status', 'failed');
        $this->getJson("/api/v1/imports/$private/items")->assertOk()->assertJsonCount(0, 'data');
    }

    private function user(string $name): User
    {
        return User::create(['email' => $name.'@example.test', 'password' => Hash::make('testing-password')]);
    }

    private function video(string $id): array
    {
        return ['contentDetails' => ['videoId' => $id], 'snippet' => ['title' => 'Example clip']];
    }
}
