<?php

namespace App\Services;

use App\Events\CircuitChanged;
use App\Models\Circuit;
use App\Models\CircuitComponent;
use App\Models\CircuitWire;
use Illuminate\Support\Facades\DB;

class CircuitService
{
    public function createCircuit(array $data): Circuit
    {
        return Circuit::create($data + ['canvas_width' => 1200, 'canvas_height' => 720]);
    }

    public function getCircuitPayload(Circuit $circuit): array
    {
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

    public function clearCircuit(Circuit $circuit): array
    {
        DB::transaction(function () use ($circuit) {
            $circuit->wires()->delete();
            $circuit->components()->delete();
            $circuit->increment('revision');
        });

        $circuit->refresh();
        broadcast(new CircuitChanged($circuit->id, 'circuit.cleared', $circuit->revision))->toOthers();

        return $this->getCircuitPayload($circuit);
    }

    public function loadDemoCircuit(Circuit $circuit): array
    {
        DB::transaction(function () use ($circuit) {
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

        return $this->getCircuitPayload($circuit);
    }
}
