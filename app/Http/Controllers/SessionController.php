<?php

namespace App\Http\Controllers;

use App\Events\CircuitChanged;
use App\Models\SessionUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SessionController extends Controller
{
    public function ping(Request $request): Response
    {
        $uuid = $this->sessionUuid($request);

        SessionUser::query()
            ->where('session_uuid', $uuid)
            ->whereNull('left_at')
            ->update(['last_seen_at' => now()]);

        return response()->noContent();
    }

    public function leave(Request $request): Response
    {
        $uuid = $this->sessionUuid($request);

        $circuitIds = SessionUser::query()
            ->where('session_uuid', $uuid)
            ->whereNull('left_at')
            ->pluck('circuit_id')
            ->unique()
            ->values();

        if ($circuitIds->isEmpty()) {
            return response()->noContent();
        }

        SessionUser::query()
            ->where('session_uuid', $uuid)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);

        foreach ($circuitIds as $circuitId) {
            CircuitChanged::dispatch((int) $circuitId, 'participant.left');
        }

        return response()->noContent();
    }

    private function sessionUuid(Request $request): string
    {
        $uuid = $request->header('X-Session-Uuid') ?: $request->input('session_uuid');

        if (! $uuid && is_string($request->getContent()) && $request->getContent() !== '') {
            $decoded = json_decode($request->getContent(), true);
            $uuid = is_array($decoded) ? ($decoded['session_uuid'] ?? null) : null;
        }

        validator(
            ['session_uuid' => $uuid],
            ['session_uuid' => ['required', 'uuid']],
        )->validate();

        return $uuid;
    }
}
