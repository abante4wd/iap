<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'type',
        'google_product_id',
        'apple_product_id',
    ];
}
