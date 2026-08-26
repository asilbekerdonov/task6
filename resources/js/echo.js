import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/realtime/auth',
});

window.joinCircuitPresence = (circuitId, sessionUuid, handlers) => {
    window.Echo.leave(`circuit.${circuitId}`);
    window.Echo.options.auth = window.Echo.options.auth || {};
    window.Echo.options.auth.headers = window.Echo.options.auth.headers || {};
    window.Echo.options.auth.headers['X-Session-Uuid'] = sessionUuid;
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
