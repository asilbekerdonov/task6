<?php

namespace App\Http\Controllers;

use App\Events\CircuitChanged;
use App\Models\Circuit;
use App\Models\CircuitComponent;
use App\Models\CircuitWire;
use App\Models\SessionUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CircuitController extends Controller
{
    public function index() { return Circuit::withCount('components')->latest()->get(); }

    public function store(Request $request) {
        $data = $request->validate(['name' => 'required|string|max:80', 'grid_size' => 'required|integer|between:10,50']);
        return Circuit::create($data + ['canvas_width' => 1200, 'canvas_height' => 720]);
    }

    public function show(Circuit $circuit) { return $this->payload($circuit); }

    public function join(Request $request, Circuit $circuit) {
        $data = $request->validate([
            'name' => 'required|string|max:48',
            'session_uuid' => 'nullable|uuid',
        ]);

        $result = SessionUser::joinCircuit($circuit, $data['name'], $data['session_uuid'] ?? null);

        broadcast(new CircuitChanged($circuit->id, 'participant.joined'))->toOthers();

        return response()->json(
            $this->payload($circuit->fresh()) + [
                'session_uuid' => $result['session_uuid'],
                'display_name' => $result['display_name'],
            ]
        );
    }

    public function component(Request $request, Circuit $circuit) {
        $data = $request->validate(['type' => ['required', Rule::in(CircuitComponent::TYPES)], 'pos_x' => 'required|integer|between:0,1160', 'pos_y' => 'required|integer|between:0,680', 'label' => 'nullable|string|max:32']);
        $component = CircuitComponent::create($data + ['circuit_id' => $circuit->id]);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($circuit->id, 'component.created', $circuit->revision))->toOthers();
        return $component;
    }

    public function updateComponent(Request $request, CircuitComponent $component) {
        $data = $request->validate(['pos_x' => 'nullable|integer|between:0,1160', 'pos_y' => 'nullable|integer|between:0,680', 'initial_value' => 'nullable|boolean', 'label' => 'nullable|string|max:32']);
        $component->update($data);
        $circuit = Circuit::findOrFail($component->circuit_id);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($component->circuit_id, 'component.updated', $circuit->revision))->toOthers();
        return $component;
    }   

    public function removeComponent(CircuitComponent $component) {
        $circuitId = $component->circuit_id;
        $component->delete();
        $circuit = Circuit::findOrFail($circuitId);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($circuitId, 'component.deleted', $circuit->revision))->toOthers();
        return response()->noContent();
    }

    public function wire(Request $request, Circuit $circuit) {
        $data = $request->validate(['from_component_id' => 'required|integer', 'from_pin' => 'required|integer|min:0|max:2', 'to_component_id' => 'required|integer|different:from_component_id', 'to_pin' => 'required|integer|min:0|max:2']);
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

    public function removeWire(CircuitWire $wire) {
        $circuitId = $wire->circuit_id;
        $wire->delete();
        $circuit = Circuit::findOrFail($circuitId);
        $circuit->increment('revision');
        $circuit->refresh();
        broadcast(new CircuitChanged($circuitId, 'wire.deleted', $circuit->revision))->toOthers();
        return response()->noContent();
    }

    private function payload(Circuit $circuit): array {
        $activeParticipants = $circuit->participants()
            ->alive()
            ->orderBy('joined_at')
            ->get();

        return [
            'circuit' => $circuit,
            'components' => $circuit->components()->get(),
            'wires' => $circuit->wires()->get(),
            'participants' => $activeParticipants->map(fn ($p) => [
                'id' => $p->id,
                'display_name' => $p->display_name,
                'joined_at' => $p->joined_at,
            ])->values()->all(),
            'active_participants' => $activeParticipants->map(fn ($p) => [
                'display_name' => $p->display_name,
                'joined_at' => $p->joined_at,
            ])->values()->all(),
        ];
    }
}
