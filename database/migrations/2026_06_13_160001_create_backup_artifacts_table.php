<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_run_id')->constrained()->cascadeOnDelete();
            $table->string('database');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum')->nullable();
            $table->boolean('compressed')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_artifacts');
    }
};
