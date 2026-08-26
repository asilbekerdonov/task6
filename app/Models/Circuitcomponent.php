<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CircuitComponent extends Model
{
    public const TYPES = ['INPUT', 'OUTPUT', 'AND', 'OR', 'NOT', 'XOR', 'NOR', 'NAND'];
    protected $fillable = ['circuit_id', 'type', 'pos_x', 'pos_y', 'rotation', 'initial_value', 'label'];
    protected $casts = ['initial_value' => 'boolean'];
    public function circuit(): BelongsTo { return $this->belongsTo(Circuit::class); }
}
