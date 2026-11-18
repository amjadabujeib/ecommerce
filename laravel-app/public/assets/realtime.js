// One socket belongs to one GUI identity/view. Late callbacks cannot cross it.
export function createRealtimeClient({request, createSocket, onError = () => {}, scheduleRetry = setTimeout, cancelRetry = clearTimeout}) {
    let generation = 0;
    let socket = null;
    let desired = null;
    let activeKey = null;
    let targetKey = null;
    let retryTimer = null;
    let retryAttempt = 0;

    function clearRetry() {
        if (retryTimer !== null) cancelRetry(retryTimer);
        retryTimer = null;
    }
    function disconnect() {
        generation++;
        clearRetry();
        const previous = socket;
        socket = null;
        previous?.disconnect();
    }
    function stop() {
        desired = null;
        activeKey = targetKey = null;
        retryAttempt = 0;
        disconnect();
    }
    function retryTransient(error, ownGeneration) {
        const status = Number(error?.status) || 0;
        if (generation !== ownGeneration || !desired || retryTimer !== null) return;
        if (status && status !== 408 && status !== 429 && status < 500) return;
        // Retry recovery only, with one timer and a capped delay; never poll order status.
        const delay = Math.min(30000, 1000 * 2 ** Math.min(retryAttempt++, 5));
        retryTimer = scheduleRetry(() => {
            retryTimer = null;
            if (generation !== ownGeneration || !desired) return;
            activeKey = null;
            void watch(desired);
        }, delay);
    }
    async function watch(options) {
        desired = options;
        const key = `${options.identity}:${[...new Set(options.channels)].sort().join(',')}`;
        if (key === activeKey) return;
        if (key !== targetKey) { targetKey = key; retryAttempt = 0; }
        activeKey = key;
        disconnect();
        const ownGeneration = generation;
        const pending = new Set(options.channels);
        const authorizationFailures = new Map();
        try {
            const {data: config} = await request('/realtime/config');
            if (generation !== ownGeneration || !config.enabled || !options.channels.length) return;
            const current = await createSocket(config, (params, callback) => {
                if (generation !== ownGeneration) return;
                pending.add(params.channelName);
                request('/realtime/auth', {method: 'POST', body: {socket_id: params.socketId, channel_name: params.channelName}}).then(result => {
                    if (generation === ownGeneration) {
                        authorizationFailures.delete(params.channelName);
                        callback(null, result);
                    }
                }).catch(error => {
                    if (generation === ownGeneration) {
                        // Pusher's AuthError event may omit status on custom authorizer errors.
                        authorizationFailures.set(params.channelName, error);
                        callback(error, null);
                        retryTransient(error, ownGeneration);
                    }
                });
            });
            if (generation !== ownGeneration) { current.disconnect(); return; }
            socket = current;
            for (const name of new Set(options.channels)) {
                const channel = current.subscribe(name);
                // Pusher resubscribes on reconnect; catch up after authorization.
                channel.bind('pusher:subscription_succeeded', () => {
                    if (generation !== ownGeneration) return;
                    pending.delete(name);
                    if (!pending.size) { retryAttempt = 0; clearRetry(); }
                    Promise.resolve(options.onCatchup?.()).catch(error => {
                        if (generation === ownGeneration) onError(error);
                    });
                });
                for (const event of ['order.created', 'order.status_changed']) {
                    channel.bind(event, payload => {
                        if (generation === ownGeneration) options.onEvent?.(payload);
                    });
                }
                channel.bind('pusher:subscription_error', error => {
                    if (generation !== ownGeneration) return;
                    const reason = authorizationFailures.get(name) || error;
                    onError(new Error(`Live updates authorization failed (${reason?.status || 'unavailable'}).`));
                    retryTransient(reason, ownGeneration);
                });
            }
        } catch (error) {
            if (generation === ownGeneration) {
                activeKey = null;
                onError(error);
                retryTransient(error, ownGeneration);
            }
        }
    }
    function rotate() {
        if (!desired) return;
        const next = desired;
        activeKey = null;
        // End the old socket before authorization using the newly rotated token.
        void watch(next);
    }
    return {watch, stop, rotate};
}

// Each GUI session owns its drain; an old request cannot block or clear a new one.
export function createCatchupCoordinator({load, onError = () => {}}) {
    let session = {pending: false, running: null};
    function reset() {
        session.pending = false;
        session = {pending: false, running: null};
    }
    function request() {
        const ownSession = session;
        ownSession.pending = true;
        ownSession.running ??= Promise.resolve().then(async () => {
            try {
                while (ownSession === session && ownSession.pending) {
                    ownSession.pending = false;
                    try { await load(); }
                    catch (error) { if (ownSession === session) onError(error); }
                }
            } finally { ownSession.running = null; }
        });
        return ownSession.running;
    }
    return {request, reset};
}

let pusherScript;
export async function createPusherSocket(config, authorize) {
    if (!globalThis.Pusher) {
        pusherScript ??= new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = '/assets/vendor/pusher.min.js';
            script.onload = resolve;
            script.onerror = () => { pusherScript = null; reject(new Error('Live updates could not be loaded.')); };
            document.head.append(script);
        });
        await pusherScript;
    }
    if (!globalThis.Pusher) throw new Error('Live updates client is unavailable.');
    return new globalThis.Pusher(config.key, {
        wsHost: config.wsHost, wsPort: config.wsPort, wssPort: config.wssPort,
        forceTLS: config.forceTLS, cluster: 'mt1',
        enabledTransports: ['ws', 'wss'], disableStats: true,
        channelAuthorization: {customHandler: authorize},
    });
}

// Keep complete HTTP snapshots while applying only event fields safe for rendering.
export function createOrderState() {
    const snapshots = new Map();
    const revisions = new Map();
    const events = new Set();
    const changes = new Map();
    function clear() { snapshots.clear(); revisions.clear(); events.clear(); changes.clear(); }
    function apply(event) {
        const id = Number(event?.order_id), revision = Number(event?.order_revision);
        if (!Number.isSafeInteger(id) || id < 1 || !Number.isSafeInteger(revision) || revision < 1 || typeof event.event_id !== 'string' || !['pending', 'completed', 'cancelled'].includes(event.order?.status) || typeof event.order.status_label !== 'string') return false;
        if (events.has(event.event_id) || revision <= (revisions.get(id) || 0)) return false;
        events.add(event.event_id);
        if (events.size > 2000) events.delete(events.values().next().value);
        revisions.set(id, revision);
        const change = {status: event.order.status, status_label: event.order.status_label, revision};
        changes.set(id, change);
        const previous = snapshots.get(id);
        if (previous) snapshots.set(id, {...previous, ...change});
        return true;
    }
    function merge(rows) {
        return rows.map(row => {
            const id = Number(row.id), previous = snapshots.get(id);
            const revision = Number(row.revision) || 0;
            if (previous && revision < (Number(previous.revision) || 0)) return previous;
            const change = changes.get(id);
            const merged = change && change.revision > revision ? {...row, ...change} : row;
            snapshots.set(id, merged);
            revisions.set(id, Math.max(revision, revisions.get(id) || 0));
            return merged;
        });
    }
    return {apply, merge, clear};
}
