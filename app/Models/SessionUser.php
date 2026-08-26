<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionUser extends Model
{
    protected $fillable = ['session_uuid', 'display_name', 'circuit_id', 'joined_at', 'last_seen_at', 'left_at'];
    protected $casts = ['joined_at' => 'datetime', 'last_seen_at' => 'datetime', 'left_at' => 'datetime'];

    public function scopeAlive($query)
    {
        $ttl = (int) config('collaboration.presence_ttl_seconds', 15);

        return $query
            ->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subSeconds($ttl));
    }

    /**
     * Calculate the next available display name for a circuit.
     * The requested name is treated as an indivisible literal (no regex parsing of trailing numbers).
     * If the base name is taken, find the smallest integer N >= 2 where "$name $N" is free.
     */
    public static function calculateAvailableName(int $circuitId, string $requestedName): string
    {
        $requested = trim($requestedName);

        $activeNames = static::where('circuit_id', $circuitId)
            ->whereNull('left_at')
            ->pluck('display_name')
            ->all();

        if (!in_array($requested, $activeNames, true)) {
            return $requested;
        }

        $n = 2;
        while (in_array($requested . ' ' . $n, $activeNames, true)) {
            $n++;
        }

        return $requested . ' ' . $n;
    }

    /**
     * Transactionally join a circuit with pessimistic row locking and inline sweep.
     *
     * @return array{session: SessionUser, session_uuid: string, display_name: string, circuit: Circuit}
     */
    public static function joinCircuit(Circuit $circuit, string $name, ?string $sessionUuid = null): array
    {
        $uuid = $sessionUuid ?: (string) Str::uuid();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA busy_timeout = 10000;');
        }

        return DB::transaction(function () use ($circuit, $name, $uuid) {
            // 1. Lock the parent circuit row to serialize concurrent join attempts to this circuit
            $lockedCircuit = Circuit::where('id', $circuit->id)->lockForUpdate()->firstOrFail();

            // 2. Inline TTL-Sweep for expired sessions in this circuit
            $ttlSeconds = (int) config('collaboration.presence_ttl_seconds', 15);
            $threshold = now()->subSeconds($ttlSeconds);
            static::where('circuit_id', $lockedCircuit->id)
                ->whereNull('left_at')
                ->where('last_seen_at', '<', $threshold)
                ->update(['left_at' => now()]);

            // 3. Check existing active session for this session_uuid in THIS circuit (Refresh / Idempotency)
            $existing = static::where('circuit_id', $lockedCircuit->id)
                ->where('session_uuid', $uuid)
                ->whereNull('left_at')
                ->first();

            if ($existing) {
                $existing->update(['last_seen_at' => now()]);

                return [
                    'session' => $existing,
                    'session_uuid' => $uuid,
                    'display_name' => $existing->display_name,
                    'circuit' => $lockedCircuit,
                ];
            }

            // 4. Room capacity check (strict max 5 active collaborators)
            $activeCount = static::where('circuit_id', $lockedCircuit->id)
                ->whereNull('left_at')
                ->count();

            abort_if($activeCount >= Circuit::MAX_ONLINE_USERS, 403, 'This room already has five active collaborators.');

            // 5. Deduplicate display name
            $displayName = static::calculateAvailableName($lockedCircuit->id, $name);

            // 6. Create active session user
            $session = static::create([
                'session_uuid' => $uuid,
                'display_name' => $displayName,
                'circuit_id'   => $lockedCircuit->id,
                'joined_at'    => now(),
                'last_seen_at' => now(),
                'left_at'      => null,
            ]);

            return [
                'session' => $session,
                'session_uuid' => $uuid,
                'display_name' => $displayName,
                'circuit' => $lockedCircuit,
            ];
        }, 5);
    }
}
