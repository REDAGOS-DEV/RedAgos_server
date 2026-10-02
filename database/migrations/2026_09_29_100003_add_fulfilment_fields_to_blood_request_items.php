<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a line's remainder be closed, and let a line answer another request's line.
     *
     * quantity is untouched and stays what was asked for. Closing a line does
     * not shrink the request: it records that the rest of this line will not
     * be supplied here — the centre has none, or the hospital no longer needs
     * it — with who decided and why.
     *
     * parent_item_id marks a follow-up line: the part of another request's
     * line that a different facility has been asked to supply instead.
     * restrictOnDelete, like the request-level link, because it is history.
     *
     * closure_reason is hard-coded rather than read from LineClosureReason, so
     * a later change to the enum cannot change what this migration did.
     */
    public function up(): void
    {
        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->foreignId('parent_item_id')->nullable()->after('request_id')
                ->constrained('blood_request_items')->cascadeOnUpdate()->restrictOnDelete();

            $table->timestamp('closed_at')->nullable()->after('indication_other');

            $table->foreignId('closed_by')->nullable()->after('closed_at')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->enum('closure_reason', ['unavailable', 'not_needed'])->nullable()->after('closed_by');
            $table->string('closure_note', 255)->nullable()->after('closure_reason');

            $table->index('parent_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->dropIndex(['parent_item_id']);
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('parent_item_id');
            $table->dropColumn(['closed_at', 'closure_reason', 'closure_note']);
        });
    }
};
