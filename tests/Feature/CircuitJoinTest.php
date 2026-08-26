<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\SessionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CircuitJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_capacity_is_strictly_limited_to_five_collaborators(): void
    {
        $circuit = Circuit::create(['name' => 'Capacity Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson("/api/circuits/{$circuit->id}/join", [
                'name' => "User {$i}",
                'session_uuid' => (string) Str::uuid(),
            ]);
            $response->assertOk();
        }

        // 6th user must be rejected
        $response = $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'User 6',
            'session_uuid' => (string) Str::uuid(),
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'This room already has five active collaborators.']);

        $this->assertEquals(5, SessionUser::where('circuit_id', $circuit->id)->whereNull('left_at')->count());
    }

    public function test_inline_sweep_frees_a_slot_when_collaborator_session_expires(): void
    {
        $circuit = Circuit::create(['name' => 'Sweep Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);

        $sessions = [];
        for ($i = 1; $i <= 5; $i++) {
            $res = $this->postJson("/api/circuits/{$circuit->id}/join", [
                'name' => "Collaborator {$i}",
                'session_uuid' => (string) Str::uuid(),
            ])->json();
            $sessions[] = $res['session_uuid'];
        }

        // Age out the first collaborator (20 seconds ago, TTL is 15s)
        SessionUser::where('session_uuid', $sessions[0])->update([
            'last_seen_at' => now()->subSeconds(20),
        ]);

        // 6th user now attempts to join -> inline sweep runs, frees 1 slot, join succeeds
        $res = $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'New Joiner',
            'session_uuid' => (string) Str::uuid(),
        ]);

        $res->assertOk();

        // Expired user was marked as left
        $this->assertNotNull(SessionUser::where('session_uuid', $sessions[0])->first()->left_at);

        // Active count in room is exactly 5
        $this->assertEquals(5, SessionUser::where('circuit_id', $circuit->id)->whereNull('left_at')->count());
    }

    public function test_display_name_deduplication_assigns_numbered_suffixes_and_handles_literals(): void
    {
        $circuit = Circuit::create(['name' => 'Names Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);

        $r1 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Alex'])->assertOk()->json();
        $this->assertEquals('Alex', $r1['display_name']);

        $r2 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Alex'])->assertOk()->json();
        $this->assertEquals('Alex 2', $r2['display_name']);

        $r3 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Alex'])->assertOk()->json();
        $this->assertEquals('Alex 3', $r3['display_name']);

        // Test indivisible literal with existing digits
        $r4 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Agent 007'])->assertOk()->json();
        $this->assertEquals('Agent 007', $r4['display_name']);

        $r5 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Agent 007'])->assertOk()->json();
        $this->assertEquals('Agent 007 2', $r5['display_name']);
    }

    public function test_base_name_is_reclaimed_when_previous_owner_is_swept(): void
    {
        $circuit = Circuit::create(['name' => 'Reclaim Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);

        $uuid1 = (string) Str::uuid();
        $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Alex',
            'session_uuid' => $uuid1,
        ])->assertOk();

        // Expire the first Alex
        SessionUser::where('session_uuid', $uuid1)->update([
            'last_seen_at' => now()->subSeconds(20),
        ]);

        // Second Alex joins -> should get base 'Alex' since previous was swept
        $r2 = $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Alex',
            'session_uuid' => (string) Str::uuid(),
        ])->assertOk()->json();

        $this->assertEquals('Alex', $r2['display_name']);
    }

    public function test_gap_filling_in_name_deduplication(): void
    {
        $circuit = Circuit::create(['name' => 'Gap Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);

        $r1 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'John'])->assertOk()->json();
        $r2 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'John'])->assertOk()->json();
        $r3 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'John'])->assertOk()->json();

        $this->assertEquals('John', $r1['display_name']);
        $this->assertEquals('John 2', $r2['display_name']);
        $this->assertEquals('John 3', $r3['display_name']);

        // John 2 leaves
        SessionUser::where('session_uuid', $r2['session_uuid'])->update(['left_at' => now()]);

        // Next join with 'John' should fill the lowest available slot: 'John 2'
        $r4 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'John'])->assertOk()->json();
        $this->assertEquals('John 2', $r4['display_name']);
    }

    public function test_refresh_session_preserves_display_name_and_does_not_consume_new_slot(): void
    {
        $circuit = Circuit::create(['name' => 'Refresh Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);
        $uuid = (string) Str::uuid();

        $r1 = $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Grace',
            'session_uuid' => $uuid,
        ])->assertOk()->json();

        $this->assertEquals('Grace', $r1['display_name']);

        // Refresh with same session_uuid
        $r2 = $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Grace',
            'session_uuid' => $uuid,
        ])->assertOk()->json();

        $this->assertEquals('Grace', $r2['display_name']);
        $this->assertEquals(1, SessionUser::where('circuit_id', $circuit->id)->whereNull('left_at')->count());
    }

    public function test_single_session_uuid_can_participate_in_multiple_circuits_simultaneously(): void
    {
        $circuitA = Circuit::create(['name' => 'Circuit A', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);
        $circuitB = Circuit::create(['name' => 'Circuit B', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);

        $uuid = (string) Str::uuid();

        $rA = $this->postJson("/api/circuits/{$circuitA->id}/join", [
            'name' => 'Ada',
            'session_uuid' => $uuid,
        ])->assertOk()->json();

        $rB = $this->postJson("/api/circuits/{$circuitB->id}/join", [
            'name' => 'Ada',
            'session_uuid' => $uuid,
        ])->assertOk()->json();

        $this->assertEquals('Ada', $rA['display_name']);
        $this->assertEquals('Ada', $rB['display_name']);

        // Both sessions remain active in their respective circuits
        $sessionA = SessionUser::where('circuit_id', $circuitA->id)->where('session_uuid', $uuid)->first();
        $sessionB = SessionUser::where('circuit_id', $circuitB->id)->where('session_uuid', $uuid)->first();

        $this->assertNotNull($sessionA);
        $this->assertNull($sessionA->left_at);
        $this->assertNotNull($sessionB);
        $this->assertNull($sessionB->left_at);
    }

    public function test_join_response_includes_instant_presence_active_participants(): void
    {
        $circuit = Circuit::create(['name' => 'Instant Presence Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);

        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Alice'])->assertOk();

        $res = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Bob'])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('active_participants', $res);
        $this->assertCount(2, $res['active_participants']);

        $names = array_column($res['active_participants'], 'display_name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function test_idempotent_duplicate_join_race_does_not_create_duplicate_records(): void
    {
        $circuit = Circuit::create(['name' => 'Duplicate Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);
        $uuid = (string) Str::uuid();

        // Simulate simultaneous or rapid join requests from the same session_uuid
        $r1 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Tester', 'session_uuid' => $uuid])->assertOk()->json();
        $r2 = $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Tester', 'session_uuid' => $uuid])->assertOk()->json();

        $this->assertEquals($r1['display_name'], $r2['display_name']);
        $this->assertEquals(1, SessionUser::where('circuit_id', $circuit->id)->where('session_uuid', $uuid)->count());
    }

    public function test_session_uuid_is_never_leaked_in_public_payload_or_participants_list(): void
    {
        $circuit = Circuit::create(['name' => 'Security Lab', 'grid_size' => 20, 'canvas_width' => 1200, 'canvas_height' => 720]);
        $secretUuidAlice = (string) Str::uuid();

        $joinAlice = $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Alice',
            'session_uuid' => $secretUuidAlice,
        ])->assertOk()->json();

        // Alice receives her own session_uuid at the root of the join response
        $this->assertEquals($secretUuidAlice, $joinAlice['session_uuid']);

        // But session_uuid is NOT exposed in the public participants or active_participants lists
        foreach ($joinAlice['participants'] as $participant) {
            $this->assertArrayNotHasKey('session_uuid', $participant, 'session_uuid must not be leaked in participants array');
        }
        foreach ($joinAlice['active_participants'] as $participant) {
            $this->assertArrayNotHasKey('session_uuid', $participant, 'session_uuid must not be leaked in active_participants array');
        }

        // Bob requests circuit status via GET /api/circuits/{id}
        $publicShow = $this->getJson("/api/circuits/{$circuit->id}")->assertOk()->json();
        foreach ($publicShow['participants'] as $participant) {
            $this->assertArrayNotHasKey('session_uuid', $participant, 'session_uuid must not be leaked in GET show payload');
        }
        foreach ($publicShow['active_participants'] as $participant) {
            $this->assertArrayNotHasKey('session_uuid', $participant, 'session_uuid must not be leaked in GET show payload');
        }
    }
}
