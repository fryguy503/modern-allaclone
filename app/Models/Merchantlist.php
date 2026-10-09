<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Merchantlist extends Model
{
    use HasFactory;

    protected $connection = 'eqemu';
    protected $table = 'merchantlist';

    public function npc(): BelongsTo
    {
        return $this->belongsTo(NpcType::class, 'merchantid', 'merchant_id');
    }

    public function items(): HasOne
    {
        return $this->hasOne(Item::class, 'id', 'item')
            ->select([
                'id', 'Name', 'icon', 'itemtype', 'slots', 'bagslots', 'bagwr', 'augtype', 'price', 'sellrate',
                'pointtype', 'ldontheme', 'ldonsold', 'ldonprice',
            ]);
    }
}
