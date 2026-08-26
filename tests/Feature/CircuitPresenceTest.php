<?php

namespace Tests\Feature;

use App\Events\CircuitChanged;
use App\Models\Circuit;
use App\Models\SessionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class CircuitPresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_updates_last_seen_at_for_the_active_session(): void
    {
        $circuit = $this->makeCircuit();
        $uuid = (string) Str::uuid();
        $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Ada',
            'session_uuid' => $uuid,
        ])->assertOk();

        $session = SessionUser::where('session_uuid', $uuid)->first();
        $session->update(['last_seen_at' => now()->subSeconds(8)]);
        $before = $session->fresh()->last_seen_at;

        $this->withHeader('X-Session-Uuid', $uuid)
            ->postJson('/api/sessions/ping')
            ->assertNoContent();

        $after = SessionUser::where('session_uuid', $uuid)->first()->last_seen_at;
        $this->assertTrue($after->greaterThan($before));
        $this->assertNull(SessionUser::where('session_uuid', $uuid)->first()->left_at);
    }

    public function test_ping_accepts_session_uuid_in_the_json_body(): void
    {
        $circuit = $this->makeCircuit();
        $uuid = (string) Str::uuid();
        $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Ada',
            'session_uuid' => $uuid,
        ])->assertOk();

        SessionUser::where('session_uuid', $uuid)->update(['last_seen_at' => now()->subSeconds(8)]);
        $before = SessionUser::where('session_uuid', $uuid)->first()->last_seen_at;

        $this->postJson('/api/sessions/ping', ['session_uuid' => $uuid])->assertNoContent();

        $this->assertTrue(
            SessionUser::where('session_uuid', $uuid)->first()->last_seen_at->greaterThan($before)
        );
    }

    public function test_ping_does_not_touch_another_users_session(): void
    {
        $circuit = $this->makeCircuit();
        $alice = (string) Str::uuid();
        $bob = (string) Str::uuid();

        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Alice', 'session_uuid' => $alice])->assertOk();
        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Bob', 'session_uuid' => $bob])->assertOk();

        $aged = now()->subSeconds(8);
        SessionUser::where('session_uuid', $bob)->update(['last_seen_at' => $aged]);
        $bobBefore = SessionUser::where('session_uuid', $bob)->first()->last_seen_at->timestamp;

        $this->withHeader('X-Session-Uuid', $alice)
            ->postJson('/api/sessions/ping')
            ->assertNoContent();

        $this->assertSame(
            $bobBefore,
            SessionUser::where('session_uuid', $bob)->first()->last_seen_at->timestamp
        );
    }

    public function test_leave_sets_left_at_and_frees_the_slot_and_name(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = $this->makeCircuit();
        $uuid = (string) Str::uuid();
        $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Ada',
            'session_uuid' => $uuid,
        ])->assertOk();

        $this->postJson('/api/sessions/leave', ['session_uuid' => $uuid])->assertNoContent();

        $session = SessionUser::where('session_uuid', $uuid)->first();
        $this->assertNotNull($session->left_at);
        $this->assertEquals(0, SessionUser::where('circuit_id', $circuit->id)->whereNull('left_at')->count());

        Event::assertDispatched(fn (CircuitChanged $event) => $event->circuitId === $circuit->id && $event->action === 'participant.left');

        $rejoin = $this->postJson("/api/circuits/{$circuit->id}/join", [
            'name' => 'Ada',
            'session_uuid' => (string) Str::uuid(),
        ])->assertOk()->json();

        $this->assertEquals('Ada', $rejoin['display_name']);
        $this->assertEquals(1, SessionUser::where('circuit_id', $circuit->id)->whereNull('left_at')->count());
    }

    public function test_leave_does_not_mark_another_users_session_as_left(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = $this->makeCircuit();
        $alice = (string) Str::uuid();
        $bob = (string) Str::uuid();

        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Alice', 'session_uuid' => $alice])->assertOk();
        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Bob', 'session_uuid' => $bob])->assertOk();

        $this->postJson('/api/sessions/leave', ['session_uuid' => $alice])->assertNoContent();

        $this->assertNotNull(SessionUser::where('session_uuid', $alice)->first()->left_at);
        $this->assertNull(SessionUser::where('session_uuid', $bob)->first()->left_at);
    }

    public function test_leave_parses_raw_json_when_content_type_is_text_plain(): void
    {
        $circuit = $this->makeCircuit();
        $uuid = (string) Str::uuid();
        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Ada', 'session_uuid' => $uuid])->assertOk();

        $this->call('POST', '/api/sessions/leave', [], [], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['session_uuid' => $uuid]))->assertNoContent();

        $this->assertNotNull(SessionUser::where('session_uuid', $uuid)->first()->left_at);
    }

    public function test_leave_is_idempotent_when_the_session_already_left(): void
    {
        $circuit = $this->makeCircuit();
        $uuid = (string) Str::uuid();
        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Ada', 'session_uuid' => $uuid])->assertOk();

        Event::fake([CircuitChanged::class]);

        $this->postJson('/api/sessions/leave', ['session_uuid' => $uuid])->assertNoContent();
        Event::assertDispatchedTimes(CircuitChanged::class, 1);

        $this->postJson('/api/sessions/leave', ['session_uuid' => $uuid])->assertNoContent();
        Event::assertDispatchedTimes(CircuitChanged::class, 1);
    }

    public function test_sweep_presence_marks_stale_users_left_and_broadcasts(): void
    {
        Event::fake([CircuitChanged::class]);

        $circuit = $this->makeCircuit();
        $fresh = (string) Str::uuid();
        $stale = (string) Str::uuid();

        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Fresh', 'session_uuid' => $fresh])->assertOk();
        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Stale', 'session_uuid' => $stale])->assertOk();

        SessionUser::where('session_uuid', $stale)->update([
            'last_seen_at' => now()->subSeconds(20),
        ]);

        $this->artisan('circuit:sweep-presence')->assertSuccessful();

        $this->assertNull(SessionUser::where('session_uuid', $fresh)->first()->left_at);
        $this->assertNotNull(SessionUser::where('session_uuid', $stale)->first()->left_at);

        Event::assertDispatched(fn (CircuitChanged $event) => $event->circuitId === $circuit->id && $event->action === 'participant.left');
    }

    public function test_sweep_presence_is_idempotent_when_nobody_is_stale(): void
    {
        $circuit = $this->makeCircuit();
        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Ada'])->assertOk();

        Event::fake([CircuitChanged::class]);

        $this->artisan('circuit:sweep-presence')->assertSuccessful();
        $this->artisan('circuit:sweep-presence')->assertSuccessful();

        Event::assertNotDispatched(CircuitChanged::class);
        $this->assertEquals(1, SessionUser::where('circuit_id', $circuit->id)->whereNull('left_at')->count());
    }

    public function test_expired_collaborator_is_omitted_from_circuit_payload(): void
    {
        $circuit = $this->makeCircuit();
        $alive = (string) Str::uuid();
        $expired = (string) Str::uuid();

        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Alive', 'session_uuid' => $alive])->assertOk();
        $this->postJson("/api/circuits/{$circuit->id}/join", ['name' => 'Expired', 'session_uuid' => $expired])->assertOk();

        SessionUser::where('session_uuid', $expired)->update([
            'last_seen_at' => now()->subSeconds(20),
        ]);

        $payload = $this->getJson("/api/circuits/{$circuit->id}")->assertOk()->json();

        $participantNames = array_column($payload['participants'], 'display_name');
        $activeNames = array_column($payload['active_participants'], 'display_name');

        $this->assertEquals(['Alive'], $participantNames);
        $this->assertEquals(['Alive'], $activeNames);
        $this->assertNull(SessionUser::where('session_uuid', $expired)->first()->left_at);
    }

    private function makeCircuit(): Circuit
    {
        return Circuit::create([
            'name' => 'Presence Lab',
            'grid_size' => 20,
            'canvas_width' => 1200,
            'canvas_height' => 720,
        ]);
    }
}
