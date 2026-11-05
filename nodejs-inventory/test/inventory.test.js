import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import grpc from '@grpc/grpc-js';
import { newId, startInventory } from './helpers.js';

describe('inventory service over gRPC', () => {
  let inventory;

  before(async () => {
    inventory = await startInventory();
  });

  after(async () => {
    await inventory.stop();
  });

  const stockOf = async (productId) => {
    const { levels } = await inventory.call('GetStock', { product_ids: [productId] });

    return levels[0].available;
  };

  test('GetStock reports set stock, and 0 for unknown products', async () => {
    await inventory.call('SetStock', { product_id: 1, quantity: 7 });

    const { levels } = await inventory.call('GetStock', { product_ids: [1, 999] });

    assert.deepEqual(levels, [
      { product_id: 1, available: 7 },
      { product_id: 999, available: 0 },
    ]);
  });

  test('Reserve takes the stock of every line', async () => {
    await inventory.call('SetStock', { product_id: 10, quantity: 5 });
    await inventory.call('SetStock', { product_id: 11, quantity: 3 });

    const response = await inventory.call('Reserve', {
      reservation_id: newId(),
      items: [
        { product_id: 10, quantity: 2 },
        { product_id: 11, quantity: 3 },
      ],
    });

    assert.equal(response.status, 'RESERVATION_STATUS_RESERVED');
    assert.equal(await stockOf(10), 3);
    assert.equal(await stockOf(11), 0);
  });

  test('Reserve is all or nothing: one short line rejects the whole order', async () => {
    await inventory.call('SetStock', { product_id: 20, quantity: 5 });
    await inventory.call('SetStock', { product_id: 21, quantity: 1 });

    const response = await inventory.call('Reserve', {
      reservation_id: newId(),
      items: [
        { product_id: 20, quantity: 2 },
        { product_id: 21, quantity: 4 },
      ],
    });

    assert.equal(response.status, 'RESERVATION_STATUS_REJECTED');
    assert.deepEqual(response.shortages, [{ product_id: 21, requested: 4, available: 1 }]);
    assert.equal(await stockOf(20), 5, 'the line that fitted was not taken either');
    assert.equal(await stockOf(21), 1);
  });

  test('Reserve is idempotent: the same reservation id never takes stock twice', async () => {
    await inventory.call('SetStock', { product_id: 30, quantity: 5 });
    const reservation = { reservation_id: newId(), items: [{ product_id: 30, quantity: 2 }] };

    const first = await inventory.call('Reserve', reservation);
    const retry = await inventory.call('Reserve', reservation);

    assert.equal(first.status, 'RESERVATION_STATUS_RESERVED');
    assert.equal(retry.status, 'RESERVATION_STATUS_RESERVED');
    assert.equal(await stockOf(30), 3);
  });

  test('Release gives the units back exactly once', async () => {
    await inventory.call('SetStock', { product_id: 40, quantity: 5 });
    const id = newId();
    await inventory.call('Reserve', { reservation_id: id, items: [{ product_id: 40, quantity: 4 }] });

    assert.equal(await stockOf(40), 1);

    assert.equal((await inventory.call('Release', { reservation_id: id })).released, true);
    assert.equal((await inventory.call('Release', { reservation_id: id })).released, false);
    assert.equal(await stockOf(40), 5);
  });

  test('a Release that arrives before its Reserve blocks the late Reserve', async () => {
    // The application gave up on a timed-out Reserve and released it; the
    // Reserve itself reaches the inventory afterwards.
    await inventory.call('SetStock', { product_id: 50, quantity: 5 });
    const id = newId();

    assert.equal((await inventory.call('Release', { reservation_id: id })).released, false);

    const late = await inventory.call('Reserve', {
      reservation_id: id,
      items: [{ product_id: 50, quantity: 2 }],
    });

    assert.equal(late.status, 'RESERVATION_STATUS_RELEASED');
    assert.equal(await stockOf(50), 5);
  });

  test('race condition: many orders for the last unit at the same moment, only one wins', async () => {
    await inventory.call('SetStock', { product_id: 60, quantity: 1 });

    const results = await Promise.all(
      Array.from({ length: 25 }, () =>
        inventory.call('Reserve', { reservation_id: newId(), items: [{ product_id: 60, quantity: 1 }] }),
      ),
    );

    const reserved = results.filter((r) => r.status === 'RESERVATION_STATUS_RESERVED');
    const rejected = results.filter((r) => r.status === 'RESERVATION_STATUS_REJECTED');

    assert.equal(reserved.length, 1);
    assert.equal(rejected.length, 24);
    assert.equal(await stockOf(60), 0);
  });

  test('race condition: 50 buyers for 10 units, exactly 10 succeed and stock never goes below 0', async () => {
    await inventory.call('SetStock', { product_id: 61, quantity: 10 });

    const results = await Promise.all(
      Array.from({ length: 50 }, () =>
        inventory.call('Reserve', { reservation_id: newId(), items: [{ product_id: 61, quantity: 1 }] }),
      ),
    );

    assert.equal(results.filter((r) => r.status === 'RESERVATION_STATUS_RESERVED').length, 10);
    assert.equal(await stockOf(61), 0);
  });

  test('invalid requests are refused with INVALID_ARGUMENT', async () => {
    await assert.rejects(
      inventory.call('Reserve', { reservation_id: 'not-a-uuid', items: [{ product_id: 1, quantity: 1 }] }),
      { code: grpc.status.INVALID_ARGUMENT },
    );
    await assert.rejects(
      inventory.call('Reserve', { reservation_id: newId(), items: [{ product_id: 1, quantity: 0 }] }),
      { code: grpc.status.INVALID_ARGUMENT },
    );
    await assert.rejects(inventory.call('Reserve', { reservation_id: newId(), items: [] }), {
      code: grpc.status.INVALID_ARGUMENT,
    });
    await assert.rejects(inventory.call('SetStock', { product_id: 1, quantity: -1 }), {
      code: grpc.status.INVALID_ARGUMENT,
    });
  });
});

describe('a slow inventory service', () => {
  test('the caller stops waiting at its deadline (DEADLINE_EXCEEDED)', async () => {
    const slow = await startInventory({ artificialDelayMs: 1500 });

    try {
      const started = Date.now();

      await assert.rejects(slow.call('GetStock', { product_ids: [1] }, { deadlineMs: 300 }), {
        code: grpc.status.DEADLINE_EXCEEDED,
      });
      assert.ok(Date.now() - started < 1000, 'the caller did not wait for the slow answer');
    } finally {
      await slow.stop();
    }
  });
});
