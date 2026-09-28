<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A sync collection added after launch. A fresh database already gets it
    // from config('fitness.collections') in the first migration, so this only
    // fills it in on databases created before it existed.
    public function up(): void
    {
        if (Schema::hasTable('mealPlans')) {
            return;
        }
        Schema::create('mealPlans', function (Blueprint $table) {
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->string('id', 100);
            $table->json('payload')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->primary(['account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mealPlans');
    }
};
