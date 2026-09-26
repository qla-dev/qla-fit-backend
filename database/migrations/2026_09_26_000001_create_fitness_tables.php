<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_id')->nullable()->unique();
            $table->unsignedInteger('ai_coins')->default(0);
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        foreach (config('fitness.collections') as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
                $table->string('id', 100);
                $table->json('payload')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
                $table->primary(['account_id', 'id']);
            });
        }
        Schema::create('sync_requests', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('fingerprint', 64);
            $table->primary(['user_id', 'request_id']);
        });
        Schema::create('markai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('conversation_id')->index();
            $table->string('mode');
            $table->text('prompt');
            $table->string('status')->default('pending');
            $table->json('reply')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('markai_messages');
        Schema::dropIfExists('sync_requests');
        foreach (config('fitness.collections') as $name) {
            Schema::dropIfExists($name);
        }
        Schema::dropIfExists('personal_access_tokens');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['apple_id', 'ai_coins']));
    }
};
