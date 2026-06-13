<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // s3 | telegram

            // Whole config blob is encrypted (it holds bucket creds / bot token).
            $table->text('config');

            // Last test result
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_test_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
