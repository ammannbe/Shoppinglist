// Small fetch wrapper for the session authenticated JSON endpoints.

export class HttpError extends Error {
    constructor(response, body) {
        super(body?.message ?? `HTTP ${response.status}`);
        this.status = response.status;
        this.body = body;
    }
}

/**
 * @param {object} options
 * @param {string} [options.baseUrl] empty in the browser (same origin)
 * @param {typeof fetch} [options.fetch]
 * @param {() => string|null} [options.xsrfToken]
 */
export function createApi({ baseUrl = '', fetch: fetchImpl = globalThis.fetch.bind(globalThis), xsrfToken = cookieXsrfToken } = {}) {
    async function request(method, path, body) {
        const headers = { Accept: 'application/json' };
        const token = xsrfToken();
        if (token) headers['X-XSRF-TOKEN'] = token;
        if (body !== undefined) headers['Content-Type'] = 'application/json';

        const response = await fetchImpl(baseUrl + path, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        const data = response.status === 204 ? null : await response.json().catch(() => null);
        if (!response.ok) throw new HttpError(response, data);

        return data;
    }

    return {
        get: (path) => request('GET', path),
        post: (path, body) => request('POST', path, body ?? {}),
        put: (path, body) => request('PUT', path, body ?? {}),
        delete: (path) => request('DELETE', path),
    };
}

// Laravel sets the (encrypted) XSRF-TOKEN cookie on every response.
function cookieXsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}
