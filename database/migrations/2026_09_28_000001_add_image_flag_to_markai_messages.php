<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The photo itself is sent to the model once and never stored; the flag
    // lets history show that a message carried one.
    public function up(): void
    {
        Schema::table('markai_messages', function (Blueprint $table) {
            $table->boolean('has_image')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('markai_messages', function (Blueprint $table) {
            $table->dropColumn('has_image');
        });
    }
};
