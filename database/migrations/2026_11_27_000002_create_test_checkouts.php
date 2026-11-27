<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_checkouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 10);
            $table->string('external_id', 100)->nullable();
            $table->text('url')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('paid_until')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'external_id']);
            $table->index(['user_id', 'gateway', 'expires_at']);
            $table->index(['user_id', 'paid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_checkouts');
    }
};
