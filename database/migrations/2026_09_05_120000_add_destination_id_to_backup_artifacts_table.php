<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which destination an artifact was uploaded to.
     *
     * Until now every artifact was written to the local staging disk and the
     * `disk` column always said `local`, so a dump that had been shipped to
     * S3 was indistinguishable from one still sitting on the machine that
     * produced it. Retention needs that difference: pruning a remote artifact
     * means deleting the object from the bucket, not unlinking a path that is
     * no longer there.
     *
     * Null keeps the old meaning — the artifact is on the local disk.
     */
    public function up(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table) {
            $table->foreignId('destination_id')
                ->nullable()
                ->after('backup_run_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_id');
        });
    }
};
