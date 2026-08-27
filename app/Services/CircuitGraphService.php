<?php

namespace App\Services;

use App\Events\CircuitChanged;
use App\Models\Circuit;
use App\Models\CircuitComponent;
use App\Models\CircuitWire;

class CircuitGraphService
{
    public function createComponent(Circuit $circuit, array $data): CircuitComponent
    {
        $component = CircuitComponent::create($data + ['circuit_id' => $circuit->id]);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($circuit->id, 'component.created', $circuit->revision))->toOthers();
        return $component;
    }

    public function updateComponent(CircuitComponent $component, array $data): CircuitComponent
    {
        $component->update($data);
        $circuit = Circuit::findOrFail($component->circuit_id);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($component->circuit_id, 'component.updated', $circuit->revision))->toOthers();
        return $component;
    }

    public function deleteComponent(CircuitComponent $component): void
    {
        $circuitId = $component->circuit_id;
        $component->delete();
        $circuit = Circuit::findOrFail($circuitId);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($circuitId, 'component.deleted', $circuit->revision))->toOthers();
    }

    public function createWire(Circuit $circuit, array $data): CircuitWire
    {
        $from = CircuitComponent::where('circuit_id', $circuit->id)->findOrFail($data['from_component_id']);
        CircuitComponent::where('circuit_id', $circuit->id)->findOrFail($data['to_component_id']);
        abort_if(in_array($from->type, ['OUTPUT']), 422, 'Outputs cannot drive a wire.');
        abort_if(CircuitWire::where('to_component_id', $data['to_component_id'])->where('to_pin', $data['to_pin'])->exists(), 422, 'That input is already connected.');
        // A new A→B is invalid when B can already reach A.
        $edges = CircuitWire::where('circuit_id', $circuit->id)->get()->groupBy('from_component_id');
        $queue = [$data['to_component_id']]; $seen = [];
        while ($queue) { $id = array_pop($queue); if ($id == $data['from_component_id']) abort(422, 'Wires cannot create a feedback loop.'); if (isset($seen[$id])) continue; $seen[$id] = true; foreach ($edges[$id] ?? [] as $edge) $queue[] = $edge->to_component_id; }
        $wire = CircuitWire::create($data + ['circuit_id' => $circuit->id]);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($circuit->id, 'wire.created', $circuit->revision))->toOthers();
        return $wire;
    }

    public function deleteWire(CircuitWire $wire): void
    {
        $circuitId = $wire->circuit_id;
        $wire->delete();
        $circuit = Circuit::findOrFail($circuitId);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($circuitId, 'wire.deleted', $circuit->revision))->toOthers();
    }
}
