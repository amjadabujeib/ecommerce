import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source = await readFile(new URL('../../laravel-app/public/assets/realtime.js', import.meta.url), 'utf8');
const {createRealtimeClient, createOrderState, createCatchupCoordinator} = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const tick = () => new Promise(resolve => setImmediate(resolve));
class Events {
    handlers = new Map();
    bind(name, callback) { this.handlers.set(name, callback); }
    emit(name, value) { this.handlers.get(name)?.(value); }
}
class Socket {
    connection = new Events(); channels = new Map(); disconnected = false;
    subscribe(name) { const channel = new Events(); this.channels.set(name, channel); return channel; }
    disconnect() { this.disconnected = true; }
}
const event = (id, revision, status = 'completed') => ({event_id: id, order_id: 7, order_revision: revision, order: {id: 7, status, status_label: status, revision}});

test('duplicate and older pushes cannot revert a newer status or erase HTTP order lines', () => {
    const state = createOrderState();
    state.merge([{id: 7, revision: 1, status: 'pending', items: [{product_title: 'Book', quantity: 2}]}]);
    assert.equal(state.apply(event('completed', 2)), true);
    assert.equal(state.apply(event('completed', 2)), false);
    assert.equal(state.apply(event('old', 1, 'pending')), false);
    assert.deepEqual(state.merge([{id: 7, revision: 1, status: 'pending', items: []}]), [{id: 7, revision: 2, status: 'completed', status_label: 'completed', items: [{product_title: 'Book', quantity: 2}]}]);
    assert.equal(state.merge([{id: 7, revision: 3, status: 'cancelled'}])[0].status, 'cancelled');
    assert.equal(state.apply(event('late-completed', 2)), false);
});

test('an event received before its HTTP snapshot is preserved during catch-up', () => {
    const state = createOrderState();
    assert.equal(state.apply(event('created-or-changed', 2)), true);
    const [order] = state.merge([{id: 7, revision: 1, status: 'pending', total_amount: '29.50', items: ['line']}]);
    assert.equal(order.revision, 2); assert.equal(order.status, 'completed');
    assert.equal(order.total_amount, '29.50'); assert.deepEqual(order.items, ['line']);
    state.clear();
    assert.equal(state.merge([{id: 7, revision: 1, status: 'pending'}])[0].status, 'pending');
    assert.equal(state.apply({event_id: 'bad', order_id: 7, order_revision: 'oops', order: {}}), false);
});

test('catch-up waits for subscription success and runs again after resubscription', async () => {
    const socket = new Socket(); let catches = 0, events = 0;
    const client = createRealtimeClient({request: async () => ({data: {enabled: true}}), createSocket: async () => socket});
    await client.watch({identity: 1, channels: ['private-orders.7'], onCatchup: () => catches++, onEvent: () => events++});
    assert.equal(catches, 0);
    const channel = socket.channels.get('private-orders.7');
    channel.emit('pusher:subscription_succeeded'); assert.equal(catches, 1);
    channel.emit('order.status_changed', event('one', 2)); assert.equal(events, 1);
    socket.connection.emit('state_change', {previous: 'connecting', current: 'connected'});
    channel.emit('pusher:subscription_succeeded'); assert.equal(catches, 2);
    client.stop(); channel.emit('order.status_changed', event('two', 3));
    assert.equal(events, 1); assert.equal(socket.disconnected, true);
});

test('late config and event callbacks cannot reconnect or update a signed-out identity', async () => {
    let finishConfig, sockets = 0;
    const client = createRealtimeClient({request: () => new Promise(resolve => { finishConfig = resolve; }), createSocket: async () => { sockets++; return new Socket(); }});
    const started = client.watch({identity: 'a', channels: ['private-admin.orders']});
    client.stop(); finishConfig({data: {enabled: true}}); await started;
    assert.equal(sockets, 0);
});

test('token rotation disconnects the old socket and authorizes using the current API client', async () => {
    const sockets = [], authorizers = [], requests = []; let token = 'old';
    const client = createRealtimeClient({request: async (path, options) => {
        requests.push({path, options, token});
        return path.endsWith('/config') ? {data: {enabled: true}} : {auth: `${token}-signature`};
    }, createSocket: async (_, authorize) => { const socket = new Socket(); sockets.push(socket); authorizers.push(authorize); return socket; }});
    await client.watch({identity: 'a', channels: ['private-orders.7']});
    token = 'fresh'; client.rotate(); await tick();
    assert.equal(sockets[0].disconnected, true); assert.equal(sockets.length, 2);
    const authorization = await new Promise((resolve, reject) => authorizers[1]({socketId: '1.2', channelName: 'private-orders.7'}, (error, result) => error ? reject(error) : resolve(result)));
    assert.equal(authorization.auth, 'fresh-signature');
    assert.deepEqual(requests.at(-1).options.body, {socket_id: '1.2', channel_name: 'private-orders.7'});
    assert.equal(requests.at(-1).token, 'fresh');
});

test('switching identities disconnects and ignores the previous account event callback', async () => {
    const sockets = []; let oldEvents = 0, newEvents = 0;
    const client = createRealtimeClient({request: async () => ({data: {enabled: true}}), createSocket: async () => { const socket = new Socket(); sockets.push(socket); return socket; }});
    await client.watch({identity: 'a', channels: ['private-orders.7'], onEvent: () => oldEvents++});
    await client.watch({identity: 'b', channels: ['private-orders.9'], onEvent: () => newEvents++});
    sockets[0].channels.get('private-orders.7').emit('order.status_changed', event('old', 2));
    sockets[1].channels.get('private-orders.9').emit('order.status_changed', event('new', 2));
    assert.equal(oldEvents, 0); assert.equal(newEvents, 1); assert.equal(sockets[0].disconnected, true);
});


function retryClock() {
    const pending = new Map(); let next = 0;
    return {
        schedule(callback, delay) { const id = ++next; pending.set(id, {callback, delay}); return id; },
        cancel(id) { pending.delete(id); },
        get delays() { return [...pending.values()].map(timer => timer.delay); },
        async fire() {
            const [id, timer] = pending.entries().next().value;
            pending.delete(id); timer.callback(); await tick();
        },
    };
}

test('transient authorization failure reconnects automatically and catches up only after subscription', async () => {
    const clock = retryClock(), sockets = [], authorize = []; let attempts = 0, caughtUp = 0;
    const client = createRealtimeClient({
        request: async path => {
            if (path === '/realtime/config') return {data: {enabled: true}};
            if (++attempts === 1) throw Object.assign(new Error('temporarily unavailable'), {status: 503});
            return {auth: 'recovered-signature'};
        },
        createSocket: async (_, auth) => { const socket = new Socket(); sockets.push(socket); authorize.push(auth); return socket; },
        scheduleRetry: clock.schedule, cancelRetry: clock.cancel,
    });
    const options = {identity: 1, channels: ['private-orders.7'], onCatchup: () => caughtUp++};
    await client.watch(options);
    await new Promise(resolve => authorize[0]({socketId: '1.2', channelName: 'private-orders.7'}, error => { assert.equal(error.status, 503); resolve(); }));
    // The Pusher client drops custom error status; recovery must retain the original reason.
    sockets[0].channels.get('private-orders.7').emit('pusher:subscription_error', {type: 'AuthError'});
    assert.deepEqual(clock.delays, [1000]);
    await clock.fire();
    assert.equal(sockets[0].disconnected, true); assert.equal(sockets.length, 2);
    const result = await new Promise(resolve => authorize[1]({socketId: '3.4', channelName: 'private-orders.7'}, (_, result) => resolve(result)));
    assert.equal(result.auth, 'recovered-signature'); assert.equal(caughtUp, 0);
    sockets[1].channels.get('private-orders.7').emit('pusher:subscription_succeeded');
    assert.equal(caughtUp, 1); assert.deepEqual(clock.delays, []); client.stop();
});

test('new-session catch-up starts while the old session is pending and ignores its late failure', async () => {
    let releaseOld; let identity = 'a'; const loads = [], errors = [];
    const coordinator = createCatchupCoordinator({load: () => {
        loads.push(identity);
        return identity === 'a' ? new Promise((_, reject) => { releaseOld = reject; }) : Promise.resolve();
    }, onError: error => errors.push(error.message)});
    const old = coordinator.request(); await tick();
    identity = 'b'; coordinator.reset(); await coordinator.request();
    assert.deepEqual(loads, ['a', 'b']);
    releaseOld(new Error('old session ended')); await old;
    assert.deepEqual(errors, []);
    await coordinator.request(); assert.deepEqual(loads, ['a', 'b', 'b']);
});


test('subscription recovery backs off to a capped delay and logout cancels further retries', async () => {
    const clock = retryClock(), sockets = [];
    const client = createRealtimeClient({request: async () => ({data: {enabled: true}}), createSocket: async () => {
        const socket = new Socket(); sockets.push(socket); return socket;
    }, scheduleRetry: clock.schedule, cancelRetry: clock.cancel});
    await client.watch({identity: 'a', channels: ['private-orders.7']});
    for (const delay of [1000, 2000, 4000, 8000, 16000, 30000, 30000]) {
        sockets.at(-1).channels.get('private-orders.7').emit('pusher:subscription_error', {status: 503});
        assert.deepEqual(clock.delays, [delay]); await clock.fire();
    }
    sockets.at(-1).channels.get('private-orders.7').emit('pusher:subscription_error', {status: 503});
    client.stop(); assert.deepEqual(clock.delays, []);
    sockets.at(-1).channels.get('private-orders.7').emit('pusher:subscription_error', {status: 503});
    assert.deepEqual(clock.delays, []);
});

test('permission denial does not retry even when Pusher omits the custom error status', async () => {
    const clock = retryClock(); let authorize; const socket = new Socket();
    const client = createRealtimeClient({request: async path => {
        if (path === '/realtime/config') return {data: {enabled: true}};
        throw Object.assign(new Error('forbidden'), {status: 403});
    }, createSocket: async (_, callback) => { authorize = callback; return socket; },
    scheduleRetry: clock.schedule, cancelRetry: clock.cancel});
    await client.watch({identity: 1, channels: ['private-admin.orders']});
    await new Promise(resolve => authorize({socketId: '1.2', channelName: 'private-admin.orders'}, () => resolve()));
    socket.channels.get('private-admin.orders').emit('pusher:subscription_error', {type: 'AuthError'});
    assert.deepEqual(clock.delays, []); client.stop();
});

test('an account switch cancels the previous account retry timer', async () => {
    const clock = retryClock(), sockets = [];
    const client = createRealtimeClient({request: async () => ({data: {enabled: true}}), createSocket: async () => {
        const socket = new Socket(); sockets.push(socket); return socket;
    }, scheduleRetry: clock.schedule, cancelRetry: clock.cancel});
    await client.watch({identity: 'a', channels: ['private-orders.7']});
    const old = sockets[0].channels.get('private-orders.7');
    old.emit('pusher:subscription_error', {status: 503}); assert.deepEqual(clock.delays, [1000]);
    await client.watch({identity: 'b', channels: ['private-orders.9']});
    assert.deepEqual(clock.delays, []); assert.equal(sockets[0].disconnected, true);
    old.emit('pusher:subscription_error', {status: 503}); assert.deepEqual(clock.delays, []);
    client.stop();
});

test('partial subscription success after reconnect cannot cancel another channel retry', async () => {
    const clock = retryClock(), socket = new Socket(); let authorize, fail = false;
    const client = createRealtimeClient({request: async path => {
        if (path === '/realtime/config') return {data: {enabled: true}};
        if (fail) throw Object.assign(new Error('unavailable'), {status: 503});
        return {auth: 'signed'};
    }, createSocket: async (_, callback) => { authorize = callback; return socket; },
    scheduleRetry: clock.schedule, cancelRetry: clock.cancel});
    const channels = ['private-orders.7', 'private-orders.8'];
    await client.watch({identity: 1, channels});
    for (const name of channels) socket.channels.get(name).emit('pusher:subscription_succeeded');
    fail = true;
    await new Promise(resolve => authorize({socketId: '1.2', channelName: channels[0]}, () => resolve()));
    socket.channels.get(channels[0]).emit('pusher:subscription_error', {});
    fail = false;
    await new Promise(resolve => authorize({socketId: '1.2', channelName: channels[1]}, () => resolve()));
    socket.channels.get(channels[1]).emit('pusher:subscription_succeeded');
    assert.deepEqual(clock.delays, [1000]); client.stop();
});

test('catch-up queued during a failure is still drained in the same session', async () => {
    let fail; let loads = 0; const errors = [];
    const coordinator = createCatchupCoordinator({load: () => {
        if (++loads === 1) return new Promise((_, reject) => { fail = reject; });
        return Promise.resolve();
    }, onError: error => errors.push(error.message)});
    const first = coordinator.request(); await tick();
    const queued = coordinator.request(); fail(new Error('temporary outage'));
    await Promise.all([first, queued]);
    assert.equal(loads, 2); assert.deepEqual(errors, ['temporary outage']);
});
