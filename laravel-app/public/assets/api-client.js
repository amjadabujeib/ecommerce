// Session rotation belongs to one client, independent of DOM rendering.
export function createApiClient({initialToken = null, onToken = () => {}, onSessionEnded = () => {}, fetchImpl = globalThis.fetch} = {}) {
    let token = initialToken;
    let session = 0;
    let rotating = null;
    function setToken(value, rotation = false) {
        if (!rotation) session++;
        token = value;
        onToken(value);
    }
    async function request(path, {method = 'GET', body, refresh = true} = {}) {
        const sentToken = token, sentSession = session;
        const headers = {Accept: 'application/json'};
        if (sentToken) headers.Authorization = `Bearer ${sentToken}`;
        let payload = body;
        if (body && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(body);
        }
        const response = await fetchImpl(`/api/v1${path}`, {method, headers, body: payload});
        const result = await response.json();
        if (sentSession !== session) throw new Error('Your session changed. Please try again.');
        if (response.status === 401 && sentToken && refresh) {
            if (sentSession !== session) throw new Error('Your session changed. Please try again.');
            // A late response may belong to the token another request just rotated.
            if (token !== sentToken) return request(path, {method, body, refresh: false});
            if (!rotating || rotating.token !== sentToken || rotating.session !== sentSession) {
                const entry = {token: sentToken, session: sentSession};
                entry.promise = (async () => {
                    const renewed = await request('/refresh', {method: 'POST', refresh: false});
                    if (typeof renewed.token !== 'string' || !renewed.token) throw new Error('Session refresh failed. Please retry.');
                    if (session === sentSession && token === sentToken) setToken(renewed.token, true);
                })().finally(() => { if (rotating === entry) rotating = null; });
                rotating = entry;
            }
            try {
                await rotating.promise;
            } catch (error) {
                if (sentSession === session && error.status === 401) {
                    setToken(null);
                    onSessionEnded();
                    throw new Error('Your session has ended. Please sign in again.');
                }
                throw error;
            }
            // Never replay an old account's mutation after logout or another login.
            if (sentSession !== session) throw new Error('Your session changed. Please try again.');
            return request(path, {method, body, refresh: false});
        }
        if (!response.ok) {
            const error = new Error(Object.values(result.errors || {}).flat().join('\n') || result.message || `Request failed (${response.status}).`);
            error.status = response.status;
            throw error;
        }
        return result;
    }
    return {request, setToken: value => setToken(value)};
}
