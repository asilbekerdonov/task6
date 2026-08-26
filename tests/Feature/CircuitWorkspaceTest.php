<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_collaborator_can_build_and_evaluate_a_valid_graph(): void
    {
        $circuit = $this->postJson('/api/circuits', ['name' => 'Signal lab', 'grid_size' => 20])->assertCreated()->json();
        $this->postJson("/api/circuits/{$circuit['id']}/join", ['name' => 'Ada'])->assertOk()->assertJsonPath('display_name', 'Ada');
        $input = $this->postJson("/api/circuits/{$circuit['id']}/components", ['type' => 'INPUT', 'pos_x' => 20, 'pos_y' => 20])->assertCreated()->json();
        $gate = $this->postJson("/api/circuits/{$circuit['id']}/components", ['type' => 'NOT', 'pos_x' => 200, 'pos_y' => 20])->assertCreated()->json();
        $this->postJson("/api/circuits/{$circuit['id']}/wires", ['from_component_id' => $input['id'], 'from_pin' => 0, 'to_component_id' => $gate['id'], 'to_pin' => 0])->assertCreated();
        $this->getJson("/api/circuits/{$circuit['id']}")->assertOk()->assertJsonCount(2, 'components')->assertJsonCount(1, 'wires');
    }

    public function test_a_wire_that_closes_a_cycle_is_rejected(): void
    {
        $circuit = $this->postJson('/api/circuits', ['name' => 'No loops', 'grid_size' => 20])->json();
        $a = $this->postJson("/api/circuits/{$circuit['id']}/components", ['type' => 'NOT', 'pos_x' => 20, 'pos_y' => 20])->json();
        $b = $this->postJson("/api/circuits/{$circuit['id']}/components", ['type' => 'NOT', 'pos_x' => 200, 'pos_y' => 20])->json();
        $this->postJson("/api/circuits/{$circuit['id']}/wires", ['from_component_id' => $a['id'], 'from_pin' => 0, 'to_component_id' => $b['id'], 'to_pin' => 0])->assertCreated();
        $this->postJson("/api/circuits/{$circuit['id']}/wires", ['from_component_id' => $b['id'], 'from_pin' => 0, 'to_component_id' => $a['id'], 'to_pin' => 0])->assertStatus(422);
    }

    public function test_an_active_session_can_authorize_its_presence_channel(): void
    {
        $circuit = $this->postJson('/api/circuits', ['name' => 'Live room', 'grid_size' => 20])->json();
        $joined = $this->postJson("/api/circuits/{$circuit['id']}/join", ['name' => 'Grace'])->json();

        $this->withHeader('X-Session-Uuid', $joined['session_uuid'])
            ->postJson('/api/realtime/auth', ['socket_id' => '123.456', 'channel_name' => "presence-circuit.{$circuit['id']}"])
            ->assertOk()
            ->assertJsonStructure(['auth', 'channel_data']);
    }
}
