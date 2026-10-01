<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sync collections for medications, cycle tracking and pregnancy. */
    private const TABLES = [
        'medications', 'medicationSchedules', 'medicationEntries',
        'cycleSettings', 'cycleLogs', 'cycles', 'cycleTests', 'symptomEntries',
        'pregnancies', 'pregnancyChecklist', 'pregnancyPhotos',
    ];

    // A fresh database already gets these from config('fitness.collections')
    // in the first migration, so this only fills them in on older databases.
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name)) {
                continue;
            }
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
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::dropIfExists($name);
        }
    }
};
