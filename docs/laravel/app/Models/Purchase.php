<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'platform',
        'transaction_id',
        'original_transaction_id',
        'purchase_token',
        'status',
        'receipt_payload',
        'store_response',
        'verified_at',
        'acknowledged_at',
        'rewards_granted_at',
        'pending_reason',
        'deferred_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'store_response' => 'array',
            'verified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'rewards_granted_at' => 'datetime',
            'deferred_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
