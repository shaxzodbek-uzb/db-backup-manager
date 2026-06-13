<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver'); // mysql | pgsql
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('username');
            $table->text('password')->nullable(); // encrypted

            // TLS (all encrypted, optional)
            $table->string('ssl_mode')->nullable();
            $table->text('ssl_ca')->nullable();
            $table->text('ssl_cert')->nullable();
            $table->text('ssl_key')->nullable();

            // SSH tunnel (columns ready; runtime tunnel lands next phase)
            $table->boolean('ssh_enabled')->default(false);
            $table->string('ssh_host')->nullable();
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('ssh_user')->nullable();
            $table->string('ssh_auth')->nullable(); // key | password
            $table->text('ssh_private_key')->nullable(); // encrypted
            $table->text('ssh_passphrase')->nullable(); // encrypted
            $table->text('ssh_password')->nullable(); // encrypted

            // Last test result
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_test_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
