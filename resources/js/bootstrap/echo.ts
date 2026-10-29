import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Echo<'reverb'>: laravel-echo 2.x d.ts tidak punya default type arg
let echo: Echo<'reverb'> | null = null;

export function realtimeEcho(): Echo<'reverb'> | null {
    if (echo) {
        return echo;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    (window as unknown as { Pusher: unknown }).Pusher = Pusher;

    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost:
            (import.meta.env.VITE_REVERB_HOST as string | undefined) ??
            '127.0.0.1',
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'ws') === 'wss',
        enabledTransports: ['ws', 'wss'],
        auth: {
            headers: {
                'X-CSRF-TOKEN':
                    (
                        document.querySelector(
                            'meta[name="csrf-token"]',
                        ) as HTMLMetaElement | null
                    )?.content ?? '',
            },
        },
    });

    return echo;
}
