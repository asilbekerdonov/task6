<?php

namespace Tests\Feature;

use App\Events\CircuitChanged;
use App\Models\Circuit;
use App\Models\CircuitComponent;
use App\Models\CircuitWire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CircuitRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_component_increments_revision_and_broadcasts_to_others(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = Circuit::create([
            'name' => 'Revision Lab',
            'grid_size' => 20,
            'canvas_width' => 1200,
            'canvas_height' => 720,
            'revision' => 0,
        ]);

        $this->withHeader('X-Socket-ID', '123.456')
            ->postJson("/api/circuits/{$circuit->id}/components", [
                'type' => 'INPUT',
                'pos_x' => 40,
                'pos_y' => 40,
                'label' => 'Source A',
            ])->assertCreated();

        $this->assertSame(1, $circuit->fresh()->revision);

        Event::assertDispatched(function (CircuitChanged $event) use ($circuit) {
            return $event->circuitId === $circuit->id
                && $event->action === 'component.created'
                && $event->revision === 1
                && $event->socket === '123.456';
        });
    }

    public function test_updating_a_component_increments_revision(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = Circuit::create(['name' => 'Revision Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720, 'revision' => 1]);
        $component = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'INPUT', 'pos_x' => 40, 'pos_y' => 40]);

        $this->withHeader('X-Socket-ID', '123.456')
            ->patchJson("/api/components/{$component->id}", ['pos_x' => 80, 'pos_y' => 80])
            ->assertOk();

        $this->assertSame(2, $circuit->fresh()->revision);

        Event::assertDispatched(function (CircuitChanged $event) use ($circuit) {
            return $event->circuitId === $circuit->id
                && $event->action === 'component.updated'
                && $event->revision === 2
                && $event->socket === '123.456';
        });
    }

    public function test_deleting_a_component_increments_revision(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = Circuit::create(['name' => 'Revision Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720, 'revision' => 2]);
        $component = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'NOT', 'pos_x' => 40, 'pos_y' => 40]);

        $this->deleteJson("/api/components/{$component->id}")->assertNoContent();

        $this->assertSame(3, $circuit->fresh()->revision);

        Event::assertDispatched(function (CircuitChanged $event) use ($circuit) {
            return $event->circuitId === $circuit->id
                && $event->action === 'component.deleted'
                && $event->revision === 3;
        });
    }

    public function test_creating_and_deleting_wires_increments_revision(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = Circuit::create(['name' => 'Wire Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720, 'revision' => 0]);
        $input = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'INPUT', 'pos_x' => 20, 'pos_y' => 20]);
        $gate = CircuitComponent::create(['circuit_id' => $circuit->id, 'type' => 'NOT', 'pos_x' => 200, 'pos_y' => 20]);

        $wireRes = $this->postJson("/api/circuits/{$circuit->id}/wires", [
            'from_component_id' => $input->id,
            'from_pin' => 0,
            'to_component_id' => $gate->id,
            'to_pin' => 0,
        ])->assertCreated()->json();

        $this->assertSame(1, $circuit->fresh()->revision);

        Event::assertDispatched(function (CircuitChanged $event) use ($circuit) {
            return $event->circuitId === $circuit->id
                && $event->action === 'wire.created'
                && $event->revision === 1;
        });

        $this->deleteJson("/api/wires/{$wireRes['id']}")->assertNoContent();

        $this->assertSame(2, $circuit->fresh()->revision);

        Event::assertDispatched(function (CircuitChanged $event) use ($circuit) {
            return $event->circuitId === $circuit->id
                && $event->action === 'wire.deleted'
                && $event->revision === 2;
        });
    }
}
