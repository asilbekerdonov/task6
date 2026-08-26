<?php

namespace Tests\Feature;

use App\Events\CircuitChanged;
use App\Models\Circuit;
use App\Models\CircuitComponent;
use App\Models\CircuitWire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CircuitDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_load_demo_creates_the_3_inverter_circuit_with_strictly_two_not_gates(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = Circuit::create([
            'name' => 'Demo Lab',
            'grid_size' => 20,
            'canvas_width' => 1200,
            'canvas_height' => 720,
            'revision' => 0,
        ]);

        $response = $this->postJson("/api/circuits/{$circuit->id}/demo")->assertOk();
        $payload = $response->json();

        $components = CircuitComponent::where('circuit_id', $circuit->id)->get();
        $wires = CircuitWire::where('circuit_id', $circuit->id)->get();

        // 1. Verify inputs and outputs count
        $inputs = $components->where('type', 'INPUT');
        $outputs = $components->where('type', 'OUTPUT');
        $nots = $components->where('type', 'NOT');

        $this->assertCount(3, $inputs, 'Must have 3 inputs (X, Y, Z)');
        $this->assertCount(3, $outputs, 'Must have 3 outputs (~X, ~Y, ~Z)');
        $this->assertCount(2, $nots, 'Killer requirement: Must have STRICTLY 2 NOT gates');

        // 2. Verify wires are established
        $this->assertGreaterThan(20, $wires->count(), 'Must have valid wiring connecting inputs to outputs');

        // 3. Verify revision increment
        $this->assertSame(1, $circuit->fresh()->revision);

        Event::assertDispatched(function (CircuitChanged $event) use ($circuit) {
            return $event->circuitId === $circuit->id
                && $event->action === 'circuit.loaded_demo'
                && $event->revision === 1;
        });
    }

    public function test_clear_circuit_removes_all_components_and_wires(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = Circuit::create(['name' => 'Clear Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720, 'revision' => 1]);
        CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'INPUT', 'pos_x' => 40, 'pos_y' => 40]);

        $this->postJson("/api/circuits/{$circuit->id}/clear")->assertOk();

        $this->assertSame(0, CircuitComponent::where('circuit_id', $circuit->id)->count());
        $this->assertSame(0, CircuitWire::where('circuit_id', $circuit->id)->count());
        $this->assertSame(2, $circuit->fresh()->revision);

        Event::assertDispatched(function (CircuitChanged $event) use ($circuit) {
            return $event->circuitId === $circuit->id
                && $event->action === 'circuit.cleared'
                && $event->revision === 2;
        });
    }
}
