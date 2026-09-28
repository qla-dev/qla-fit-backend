<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // `email` stays the unique placeholder derived from the Apple subject: an
    // Apple email can change or be a private relay, so it is kept beside it
    // for display and never used to find or merge an account.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_email')->nullable();
            $table->boolean('apple_email_private')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['apple_email', 'apple_email_private']);
        });
    }
};
