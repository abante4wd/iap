<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('pending_reason')->nullable()->after('status'); // ask_to_buy, pending_payment, unknown
            $table->timestamp('deferred_at')->nullable()->after('rewards_granted_at');
            $table->timestamp('completed_at')->nullable()->after('deferred_at');

            $table->index(['platform', 'purchase_token', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['platform', 'purchase_token', 'status']);
            $table->dropColumn(['pending_reason', 'deferred_at', 'completed_at']);
        });
    }
};
