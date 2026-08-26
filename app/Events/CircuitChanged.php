<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CircuitChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $circuitId, public string $action, public ?int $revision = null) {}

    public function broadcastOn(): array { return [new PresenceChannel('circuit.'.$this->circuitId)]; }
    public function broadcastAs(): string { return 'circuit.changed'; }
    public function broadcastWith(): array {
        return array_filter([
            'action' => $this->action,
            'revision' => $this->revision,
            'at' => now()->toIso8601String(),
        ], fn ($val) => !is_null($val));
    }
}
