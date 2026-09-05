<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets one plan deliver to several destinations.
     *
     * A single `destination_id` meant a plan could go to object storage or to
     * a chat, but not both — and the only way to have both was a second plan,
     * which dumps every database a second time. Dumping is the expensive part
     * and it runs against live customer databases, so paying for it twice to
     * get a second copy is the wrong trade.
     *
     * Existing plans keep their destination: it is copied into the pivot before
     * the column is dropped.
     */
    public function up(): void
    {
        Schema::create('backup_plan_destination', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['backup_plan_id', 'destination_id']);
        });

        $now = now();

        $rows = DB::table('backup_plans')
            ->whereNotNull('destination_id')
            ->get(['id', 'destination_id']);

        foreach ($rows as $row) {
            DB::table('backup_plan_destination')->insert([
                'backup_plan_id' => $row->id,
                'destination_id' => $row->destination_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('backup_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_id');
        });
    }

    public function down(): void
    {
        Schema::table('backup_plans', function (Blueprint $table) {
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();
        });

        // Only one destination survives the reversal; the first is kept.
        $pairs = DB::table('backup_plan_destination')
            ->orderBy('id')
            ->get(['backup_plan_id', 'destination_id']);

        $seen = [];

        foreach ($pairs as $pair) {
            if (isset($seen[$pair->backup_plan_id])) {
                continue;
            }

            $seen[$pair->backup_plan_id] = true;

            DB::table('backup_plans')
                ->where('id', $pair->backup_plan_id)
                ->update(['destination_id' => $pair->destination_id]);
        }

        Schema::dropIfExists('backup_plan_destination');
    }
};
