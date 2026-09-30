<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per store transaction RevenueCat reported for an account, so
     * each is granted once: coins added, or one program unlocked.
     */
    public function up(): void
    {
        Schema::create('revenuecat_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_id')->unique();
            $table->string('product_id');
            $table->unsignedInteger('coins')->default(0);
            $table->string('program_id')->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenuecat_purchases');
    }
};
