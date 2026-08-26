<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CircuitWire extends Model
{
    protected $fillable = ['circuit_id', 'from_component_id', 'from_pin', 'to_component_id', 'to_pin'];
}
