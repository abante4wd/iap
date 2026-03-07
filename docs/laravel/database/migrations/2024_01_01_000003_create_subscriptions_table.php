<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('platform'); // google, apple
            $table->string('original_transaction_id');
            $table->string('current_transaction_id');
            $table->string('status'); // active, expired, cancelled, grace_period, paused
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->boolean('auto_renewing')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'original_transaction_id']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
