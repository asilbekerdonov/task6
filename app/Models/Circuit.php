<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Circuit extends Model
{
    public const MAX_ONLINE_USERS = 5;

    protected $fillable = ['name', 'grid_size', 'canvas_width', 'canvas_height', 'revision'];

    protected $casts = ['revision' => 'integer'];

    public function components(): HasMany { return $this->hasMany(CircuitComponent::class); }
    public function wires(): HasMany { return $this->hasMany(CircuitWire::class); }
    public function participants(): HasMany { return $this->hasMany(SessionUser::class); }
}
