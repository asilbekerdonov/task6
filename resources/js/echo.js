import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || (window.location.hostname || '127.0.0.1'),
    wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: false,
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel, options) => {
        return {
            authorize: (socketId, callback) => {
                const sessionUuid = localStorage.getItem('ch-session');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

                fetch('/api/realtime/auth', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Session-Uuid': sessionUuid || '',
                        'X-CSRF-TOKEN': csrfToken || '',
                    },
                    body: JSON.stringify({
                        socket_id: socketId,
                        channel_name: channel.name,
                        session_uuid: sessionUuid,
                    })
                })
                .then(async response => {
                    if (!response.ok) {
                        const errorData = await response.json().catch(() => ({}));
                        throw new Error(errorData.message || `Auth failed (${response.status})`);
                    }
                    return response.json();
                })
                .then(data => {
                    callback(null, data);
                })
                .catch(error => {
                    console.error('[Echo Auth Error]', error);
                    callback(error, null);
                });
            }
        };
    }
});

window.joinCircuitPresence = (circuitId, sessionUuid, handlers) => {
    window.Echo.leave(`circuit.${circuitId}`);
    return window.Echo.join(`circuit.${circuitId}`)
        .here(handlers.here)
        .joining(handlers.joining)
        .leaving(handlers.leaving)
        .listen('.circuit.changed', handlers.changed)
        .listenForWhisper('cursor', handlers.cursor)
        .error(handlers.error);
};

window.bindEchoStateChange = (callback) => {
    if (window.Echo?.connector?.pusher?.connection) {
        window.Echo.connector.pusher.connection.bind('state_change', callback);
    }
};
