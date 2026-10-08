<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('audit-toolkit.table', 'audit_events'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event', 150);
            $table->string('category', 80)->nullable();
            $table->string('description')->nullable();

            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();

            $table->dateTime('occurred_at', precision: 6);
            $table->string('source', 40)->nullable();
            $table->string('guard', 80)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->uuid('batch_id')->nullable();
            $table->uuid('request_id')->nullable();

            $table->index(['event', 'occurred_at']);
            $table->index(['category', 'occurred_at']);
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['correlation_id', 'occurred_at']);
            $table->index(['batch_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('audit-toolkit.table', 'audit_events'));
    }
};
