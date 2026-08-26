<?php

namespace App\Console\Commands;

use App\Events\CircuitChanged;
use App\Models\SessionUser;
use Illuminate\Console\Command;

class SweepPresenceCommand extends Command
{
    protected $signature = 'circuit:sweep-presence';

    protected $description = 'Mark stale collaborators as left and broadcast presence updates';

    public function handle(): int
    {
        $ttl = (int) config('collaboration.presence_ttl_seconds', 15);
        $threshold = now()->subSeconds($ttl);

        $stale = SessionUser::query()
            ->whereNull('left_at')
            ->where('last_seen_at', '<', $threshold)
            ->get(['id', 'circuit_id']);

        if ($stale->isEmpty()) {
            return self::SUCCESS;
        }

        $updated = SessionUser::query()
            ->whereIn('id', $stale->pluck('id'))
            ->whereNull('left_at')
            ->where('last_seen_at', '<', $threshold)
            ->update(['left_at' => now()]);

        if ($updated === 0) {
            return self::SUCCESS;
        }

        $affectedCircuitIds = SessionUser::query()
            ->whereIn('id', $stale->pluck('id'))
            ->whereNotNull('left_at')
            ->pluck('circuit_id')
            ->unique();

        foreach ($affectedCircuitIds as $circuitId) {
            CircuitChanged::dispatch((int) $circuitId, 'participant.left');
        }

        return self::SUCCESS;
    }
}
