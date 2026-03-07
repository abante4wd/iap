<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('platform'); // google, apple
            $table->string('transaction_id');
            $table->string('original_transaction_id')->nullable();
            $table->text('purchase_token');
            $table->string('status'); // pending, verified, failed, refunded
            $table->text('receipt_payload')->nullable();
            $table->json('store_response')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('rewards_granted_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'transaction_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
