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

    public function clear(Circuit $circuit) {
        \Illuminate\Support\Facades\DB::transaction(function () use ($circuit) {
            $circuit->wires()->delete();
            $circuit->components()->delete();
            $circuit->increment('revision');
        });
        $circuit->refresh();
        broadcast(new CircuitChanged($circuit->id, 'circuit.cleared', $circuit->revision))->toOthers();
        return response()->json($this->payload($circuit));
    }

    public function loadDemo(Circuit $circuit) {
        \Illuminate\Support\Facades\DB::transaction(function () use ($circuit) {
            $circuit->wires()->delete();
            $circuit->components()->delete();

            // Inputs
            $x = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'INPUT', 'pos_x' => 40, 'pos_y' => 80, 'label' => 'X', 'initial_value' => true]);
            $y = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'INPUT', 'pos_x' => 40, 'pos_y' => 240, 'label' => 'Y', 'initial_value' => false]);
            $z = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'INPUT', 'pos_x' => 40, 'pos_y' => 400, 'label' => 'Z', 'initial_value' => false]);

            // Level 1: Basic Pairwise Terms
            $or1  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR',  'pos_x' => 170, 'pos_y' => 40,  'label' => 'X | Y']);
            $and1 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 170, 'pos_y' => 110, 'label' => 'X & Y']);
            $and2 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 170, 'pos_y' => 180, 'label' => 'X & Z']);
            $and3 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 170, 'pos_y' => 250, 'label' => 'Y & Z']);
            $or6  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR',  'pos_x' => 170, 'pos_y' => 320, 'label' => 'Y | Z']);
            $or9  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR',  'pos_x' => 170, 'pos_y' => 390, 'label' => 'X | Z']);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $x->id, 'from_pin' => 0, 'to_component_id' => $or1->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $y->id, 'from_pin' => 0, 'to_component_id' => $or1->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $x->id, 'from_pin' => 0, 'to_component_id' => $and1->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $y->id, 'from_pin' => 0, 'to_component_id' => $and1->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $x->id, 'from_pin' => 0, 'to_component_id' => $and2->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $z->id, 'from_pin' => 0, 'to_component_id' => $and2->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $y->id, 'from_pin' => 0, 'to_component_id' => $and3->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $z->id, 'from_pin' => 0, 'to_component_id' => $and3->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $y->id, 'from_pin' => 0, 'to_component_id' => $or6->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $z->id, 'from_pin' => 0, 'to_component_id' => $or6->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $x->id, 'from_pin' => 0, 'to_component_id' => $or9->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $z->id, 'from_pin' => 0, 'to_component_id' => $or9->id, 'to_pin' => 1]);

            // Level 2: S1, S2_part, S3
            $s1   = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR',  'pos_x' => 300, 'pos_y' => 40,  'label' => 'S1']);
            $or3  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR',  'pos_x' => 300, 'pos_y' => 150, 'label' => 'XY | XZ']);
            $s3   = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 300, 'pos_y' => 260, 'label' => 'S3 (XYZ)']);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or1->id, 'from_pin' => 0, 'to_component_id' => $s1->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $z->id,   'from_pin' => 0, 'to_component_id' => $s1->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and1->id, 'from_pin' => 0, 'to_component_id' => $or3->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and2->id, 'from_pin' => 0, 'to_component_id' => $or3->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and1->id, 'from_pin' => 0, 'to_component_id' => $s3->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $z->id,    'from_pin' => 0, 'to_component_id' => $s3->id, 'to_pin' => 1]);

            // Level 3: S2 and NOT Gate 1
            $s2   = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR',  'pos_x' => 430, 'pos_y' => 150, 'label' => 'S2']);
            $not1 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'NOT', 'pos_x' => 540, 'pos_y' => 150, 'label' => 'NOT1 (~S2)']);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or3->id,  'from_pin' => 0, 'to_component_id' => $s2->id,   'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and3->id, 'from_pin' => 0, 'to_component_id' => $s2->id,   'to_pin' => 1]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $s2->id,   'from_pin' => 0, 'to_component_id' => $not1->id, 'to_pin' => 0]);

            // Level 4: A1 and NOT Gate 2
            $and5 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 540, 'pos_y' => 40,  'label' => 'S1 & ~S2']);
            $a1   = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR',  'pos_x' => 660, 'pos_y' => 90,  'label' => 'A1']);
            $not2 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'NOT', 'pos_x' => 760, 'pos_y' => 90,  'label' => 'NOT2 (~A1)']);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $s1->id,   'from_pin' => 0, 'to_component_id' => $and5->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not1->id, 'from_pin' => 0, 'to_component_id' => $and5->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $s3->id,   'from_pin' => 0, 'to_component_id' => $a1->id,   'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and5->id, 'from_pin' => 0, 'to_component_id' => $a1->id,   'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $a1->id,   'from_pin' => 0, 'to_component_id' => $not2->id, 'to_pin' => 0]);

            // Level 5: Combination Products
            $and6  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 870, 'pos_y' => 30,  'label' => 'N1 & N2']);
            $and7  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 870, 'pos_y' => 95,  'label' => 'N1&(Y|Z)']);
            $and8  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 870, 'pos_y' => 160, 'label' => 'N2&(Y&Z)']);
            $and9  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 870, 'pos_y' => 225, 'label' => 'N1&(X|Z)']);
            $and10 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 870, 'pos_y' => 290, 'label' => 'N2&(X&Z)']);
            $and11 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 870, 'pos_y' => 355, 'label' => 'N1&(X|Y)']);
            $and12 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'AND', 'pos_x' => 870, 'pos_y' => 420, 'label' => 'N2&(X&Y)']);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not1->id, 'from_pin' => 0, 'to_component_id' => $and6->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not2->id, 'from_pin' => 0, 'to_component_id' => $and6->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not1->id, 'from_pin' => 0, 'to_component_id' => $and7->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or6->id,  'from_pin' => 0, 'to_component_id' => $and7->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not2->id, 'from_pin' => 0, 'to_component_id' => $and8->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and3->id, 'from_pin' => 0, 'to_component_id' => $and8->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not1->id, 'from_pin' => 0, 'to_component_id' => $and9->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or9->id,  'from_pin' => 0, 'to_component_id' => $and9->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not2->id, 'from_pin' => 0, 'to_component_id' => $and10->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and2->id, 'from_pin' => 0, 'to_component_id' => $and10->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not1->id, 'from_pin' => 0, 'to_component_id' => $and11->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or1->id,  'from_pin' => 0, 'to_component_id' => $and11->id, 'to_pin' => 1]);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $not2->id, 'from_pin' => 0, 'to_component_id' => $and12->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and1->id, 'from_pin' => 0, 'to_component_id' => $and12->id, 'to_pin' => 1]);

            // Level 6: Output accumulators for ~X, ~Y, ~Z
            $or7  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR', 'pos_x' => 990,  'pos_y' => 50,  'label' => '~X part']);
            $or8  = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR', 'pos_x' => 1090, 'pos_y' => 80,  'label' => '~X accum']);
            $outX = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OUTPUT', 'pos_x' => 1190, 'pos_y' => 80, 'label' => '~X']);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and6->id, 'from_pin' => 0, 'to_component_id' => $or7->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and7->id, 'from_pin' => 0, 'to_component_id' => $or7->id, 'to_pin' => 1]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or7->id,  'from_pin' => 0, 'to_component_id' => $or8->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and8->id, 'from_pin' => 0, 'to_component_id' => $or8->id, 'to_pin' => 1]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or8->id,  'from_pin' => 0, 'to_component_id' => $outX->id, 'to_pin' => 0]);

            $or10 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR', 'pos_x' => 990,  'pos_y' => 240, 'label' => '~Y part']);
            $or11 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR', 'pos_x' => 1090, 'pos_y' => 260, 'label' => '~Y accum']);
            $outY = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OUTPUT', 'pos_x' => 1190, 'pos_y' => 260, 'label' => '~Y']);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and6->id,  'from_pin' => 0, 'to_component_id' => $or10->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and9->id,  'from_pin' => 0, 'to_component_id' => $or10->id, 'to_pin' => 1]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or10->id,  'from_pin' => 0, 'to_component_id' => $or11->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and10->id, 'from_pin' => 0, 'to_component_id' => $or11->id, 'to_pin' => 1]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or11->id,  'from_pin' => 0, 'to_component_id' => $outY->id,  'to_pin' => 0]);

            $or12 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR', 'pos_x' => 990,  'pos_y' => 380, 'label' => '~Z part']);
            $or13 = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OR', 'pos_x' => 1090, 'pos_y' => 400, 'label' => '~Z accum']);
            $outZ = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'OUTPUT', 'pos_x' => 1190, 'pos_y' => 400, 'label' => '~Z']);

            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and6->id,  'from_pin' => 0, 'to_component_id' => $or12->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and11->id, 'from_pin' => 0, 'to_component_id' => $or12->id, 'to_pin' => 1]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or12->id,  'from_pin' => 0, 'to_component_id' => $or13->id, 'to_pin' => 0]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $and12->id, 'from_pin' => 0, 'to_component_id' => $or13->id, 'to_pin' => 1]);
            CircuitWire::create(['circuit_id' => $circuit->id, 'from_component_id' => $or13->id,  'from_pin' => 0, 'to_component_id' => $outZ->id,  'to_pin' => 0]);

            $circuit->increment('revision');
        });

        $circuit->refresh();
        broadcast(new CircuitChanged($circuit->id, 'circuit.loaded_demo', $circuit->revision))->toOthers();

        return response()->json($this->payload($circuit));
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
