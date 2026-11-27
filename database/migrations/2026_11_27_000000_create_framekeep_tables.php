<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('email', 254)->unique();
                $table->string('password');
            });
        }

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration');
        });
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('media_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('source', 160);
            $table->string('status', 16)->default('queued');
            $table->string('label')->nullable();
            $table->text('cursor')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->string('message', 300)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'platform', 'source']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'updated_at']);
        });
        Schema::create('media_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_import_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id', 128);
            $table->string('type', 16);
            $table->string('title', 255);
            $table->text('permalink');
            $table->text('thumbnail')->nullable();
            $table->json('variants');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['media_import_id', 'remote_id']);
            $table->index(['media_import_id', 'id']);
            $table->index(['media_import_id', 'type', 'id']);
        });
        Schema::create('usage_days', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('source', 177)->nullable();
            $table->primary(['user_id', 'day']);
        });
        Schema::create('usage_downloads', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'day', 'media_item_id']);
        });
        Schema::create('media_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('selection');
            $table->string('status', 16)->default('queued');
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('message', 300)->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'updated_at']);
        });
        Schema::create('pro_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('paid_until');
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        foreach (['pro_subscriptions', 'media_exports', 'usage_downloads', 'usage_days', 'media_items',
            'media_imports', 'personal_access_tokens', 'failed_jobs', 'jobs', 'cache_locks', 'cache', 'sessions'] as $table) {
            Schema::dropIfExists($table);
        }
        // The users table can contain accounts from the previous release.
    }
};
