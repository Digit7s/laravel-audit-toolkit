<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('audit-toolkit.table', 'audit_events');

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('original_actor_type')->nullable()->after('actor_id');
            $table->string('original_actor_id')->nullable()->after('original_actor_type');
            $table->index(['original_actor_type', 'original_actor_id', 'occurred_at'], 'audit_events_original_actor_occurred_index');
        });
    }

    public function down(): void
    {
        $tableName = config('audit-toolkit.table', 'audit_events');

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropIndex('audit_events_original_actor_occurred_index');
            $table->dropColumn(['original_actor_type', 'original_actor_id']);
        });
    }
};
