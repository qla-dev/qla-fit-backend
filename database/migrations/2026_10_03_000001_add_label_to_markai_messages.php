<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // What the chat shows for a message the app wrote on the user's behalf
    // ("Plan all my macros"); the prompt with their details still goes to the
    // model, but is never shown back to them.
    public function up(): void
    {
        Schema::table('markai_messages', function (Blueprint $table) {
            $table->string('label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('markai_messages', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
