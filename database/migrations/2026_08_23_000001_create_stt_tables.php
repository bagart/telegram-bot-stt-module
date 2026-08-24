<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STT module schema (todo.stt.md §11): provider token vault and the
 * transcription dedupe/history table. The unique (bot_id, file_unique_id)
 * index doubles as the redelivery serializer (§7bis step 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stt_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bot_id', 20);
            $table->string('provider_key', 64);
            // Stored with Laravel 'encrypted' cast; never returned in full via API/UI
            $table->text('token');
            $table->unsignedBigInteger('created_by_tg_id');
            $table->string('created_by_username', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->unique(['bot_id', 'provider_key']);
        });

        Schema::create('stt_transcriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bot_id', 20);
            $table->bigInteger('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->string('file_unique_id', 128);
            $table->string('provider_key', 64);
            // ok | failed | empty
            $table->string('status', 16)->default('ok');
            $table->string('error_code', 24)->nullable();
            $table->text('result_text')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('meta')->nullable();
            $table->timestampsTz();

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->unique(['bot_id', 'file_unique_id']);
            $table->index(['bot_id', 'chat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stt_transcriptions');
        Schema::dropIfExists('stt_tokens');
    }
};
