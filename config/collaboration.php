<?php

return [
    // Shared by Reverb and polling clients: neither transport defines presence.
    'heartbeat_seconds' => 5,
    'presence_ttl_seconds' => 15,
    'presence_sweep_seconds' => 5,
];
