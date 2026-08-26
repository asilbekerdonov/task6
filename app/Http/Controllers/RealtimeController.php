<?php

namespace App\Http\Controllers;

use App\Models\SessionUser;
use Illuminate\Http\Request;
use Pusher\Pusher;

class RealtimeController extends Controller
{
    public function authenticate(Request $request)
    {
        $data = $request->validate(['socket_id' => 'required|string', 'channel_name' => 'required|string']);
        if (! preg_match('/^presence-circuit\.(\d+)$/', $data['channel_name'], $matches)) abort(403);
        $session = SessionUser::where('session_uuid', $request->header('X-Session-Uuid'))
            ->where('circuit_id', $matches[1])->whereNull('left_at')->latest('joined_at')->firstOrFail();
        $pusher = new Pusher(config('broadcasting.connections.reverb.key'), config('broadcasting.connections.reverb.secret'), config('broadcasting.connections.reverb.app_id'), config('broadcasting.connections.reverb.options'));
        return response($pusher->authorizePresenceChannel($data['channel_name'], $data['socket_id'], (string) $session->id, ['name' => $session->display_name, 'session_uuid' => $session->session_uuid]), 200, ['Content-Type' => 'application/json']);
    }
}
