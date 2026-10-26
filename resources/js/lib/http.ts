export class HttpError extends Error {
    readonly status: number;

    readonly errors: Record<string, string[]>;

    constructor(
        status: number,
        message: string,
        errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'HttpError';
        this.status = status;
        this.errors = errors;
    }

    firstError(): string {
        for (const messages of Object.values(this.errors)) {
            if (messages[0]) {
                return messages[0];
            }
        }

        return this.message;
    }
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function requestJson<T>(
    method: 'GET' | 'POST' | 'PATCH',
    url: string,
    body?: unknown,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-TOKEN'] = csrfToken();
    }

    const options: RequestInit = {
        method,
        credentials: 'same-origin',
        headers,
    };

    if (body !== undefined) {
        options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);

    const text = await response.text();
    let payload: unknown = null;

    if (text) {
        try {
            payload = JSON.parse(text);
        } catch {
            payload = null;
        }
    }

    if (!response.ok) {
        const data = (payload ?? {}) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const message =
            data.message ??
            (response.status === 419
                ? 'Sesi kedaluwarsa. Muat ulang halaman.'
                : 'Terjadi kesalahan. Coba lagi.');

        throw new HttpError(response.status, message, data.errors ?? {});
    }

    return (payload ?? {}) as T;
}

export async function getJson<T>(url: string): Promise<T> {
    return requestJson<T>('GET', url);
}

export async function postJson<T>(url: string, body: unknown): Promise<T> {
    return requestJson<T>('POST', url, body);
}

export async function patchJson<T>(url: string, body: unknown): Promise<T> {
    return requestJson<T>('PATCH', url, body);
}
