<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Marks a reply paid for by the daily free allowance rather than a coin;
    // the day's count is read back from these rows (fitness.markai_free_daily).
    public function up(): void
    {
        Schema::table('markai_messages', function (Blueprint $table) {
            $table->boolean('free')->default(false);
            $table->index(['user_id', 'free', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('markai_messages', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'free', 'created_at']);
            $table->dropColumn('free');
        });
    }
};
