<?php

namespace App\Http\Controllers;

use App\Models\SessionUser;
use Illuminate\Http\Request;
use Pusher\Pusher;

class RealtimeController extends Controller
{
    public function authenticate(Request $request)
    {
        $data = $request->validate([
            'socket_id' => 'required|string',
            'channel_name' => 'required|string'
        ]);

        if (! preg_match('/^presence-circuit\.(\d+)$/', $data['channel_name'], $matches)) {
            abort(403, 'Invalid presence channel format');
        }

        $uuid = $request->header('X-Session-Uuid')
            ?? $request->header('x-session-uuid')
            ?? $request->input('session_uuid');

        if (! $uuid) {
            abort(403, 'Missing X-Session-Uuid header');
        }

        $session = SessionUser::where('session_uuid', $uuid)
            ->where('circuit_id', $matches[1])
            ->latest('joined_at')
            ->first();

        if (! $session) {
            abort(403, 'Session user not found');
        }

        if ($session->left_at !== null) {
            $session->update(['left_at' => null, 'last_seen_at' => now()]);
        } else {
            $session->update(['last_seen_at' => now()]);
        }

        $pusher = new Pusher(
            config('broadcasting.connections.reverb.key'),
            config('broadcasting.connections.reverb.secret'),
            config('broadcasting.connections.reverb.app_id'),
            config('broadcasting.connections.reverb.options')
        );

        return response(
            $pusher->authorizePresenceChannel(
                $data['channel_name'],
                $data['socket_id'],
                (string) $session->id,
                [
                    'name' => $session->display_name,
                    'session_uuid' => $session->session_uuid
                ]
            ),
            200,
            ['Content-Type' => 'application/json']
        );
    }
}
