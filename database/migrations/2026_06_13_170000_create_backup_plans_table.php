<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('selection')->default('all');  // all | selected
            $table->json('databases')->nullable();
            $table->json('exclude_databases')->nullable();
            $table->string('cron')->default('0 2 * * *');
            $table->string('timezone')->default('UTC');
            $table->unsignedInteger('retention_days')->nullable();
            $table->unsignedInteger('retention_copies')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_plans');
    }
};
