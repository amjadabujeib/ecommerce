import { transaction } from './db.js';

/**
 * The business rules of the inventory, independent of gRPC.
 *
 * Every write runs in one database transaction and locks the stock rows it
 * changes (SELECT ... FOR UPDATE in product_id order to reduce deadlock risk).
 * When several orders want the last unit at the same moment, they queue on the
 * row, the first one takes it, and the others see 0 left.
 */
export class Inventory {
  /** @param {import('pg').Pool} pool */
  constructor(pool) {
    this.pool = pool;
  }

  /**
   * @param {number[]} productIds
   * @returns {Promise<{product_id: number, available: number}[]>} in request order
   */
  async getStock(productIds) {
    const ids = [...new Set(productIds)];

    if (ids.length === 0) {
      return [];
    }

    const { rows } = await this.pool.query(
      'SELECT product_id, quantity FROM stock WHERE product_id = ANY($1::bigint[])',
      [ids],
    );

    const quantities = new Map(rows.map((row) => [Number(row.product_id), row.quantity]));

    return ids.map((id) => ({ product_id: id, available: quantities.get(id) ?? 0 }));
  }

  /**
   * Reserve every line or nothing.
   *
   * @param {string} reservationId
   * @param {{product_id: number, quantity: number}[]} items  already validated
   * @returns {Promise<{status: 'RESERVED'|'REJECTED'|'RELEASED', shortages: {product_id: number, requested: number, available: number}[]}>}
   */
  async reserve(reservationId, items) {
    const lines = mergeLines(items);

    return transaction(this.pool, async (client) => {
      // Claiming the UUID serializes concurrent reserves and releases for it.
      const claim = await client.query(
        `INSERT INTO reservations (id, status) VALUES ($1, 'reserved')
         ON CONFLICT (id) DO NOTHING RETURNING id`,
        [reservationId],
      );

      if (claim.rowCount === 0) {
        const { rows: [reservation] } = await client.query(
          'SELECT status, shortages FROM reservations WHERE id = $1 FOR UPDATE',
          [reservationId],
        );
        const { rows: previousItems } = await client.query(
          'SELECT product_id, quantity FROM reservation_items WHERE reservation_id = $1 ORDER BY product_id',
          [reservationId],
        );

        // An early release has no payload; it blocks any late reservation.
        if (previousItems.length > 0 && (
          previousItems.length !== lines.size ||
          previousItems.some((item) => lines.get(Number(item.product_id)) !== item.quantity)
        )) {
          throw new ReservationConflict('reservation_id was already used for different items');
        }

        return { status: reservation.status.toUpperCase(), shortages: reservation.shortages };
      }

      const productIds = [...lines.keys()].sort((a, b) => a - b);
      const { rows } = await client.query(
        `SELECT product_id, quantity FROM stock
         WHERE product_id = ANY($1::bigint[]) ORDER BY product_id FOR UPDATE`,
        [productIds],
      );
      const available = new Map(rows.map((row) => [Number(row.product_id), row.quantity]));
      const shortages = productIds
        .map((productId) => ({
          product_id: productId,
          requested: lines.get(productId),
          available: available.get(productId) ?? 0,
        }))
        .filter((line) => line.requested > line.available);

      // Keep the normalized request even on rejection, so replays cannot
      // change the payload or silently turn a rejected attempt into a sale.
      for (const productId of productIds) {
        await client.query(
          'INSERT INTO reservation_items (reservation_id, product_id, quantity) VALUES ($1, $2, $3)',
          [reservationId, productId, lines.get(productId)],
        );
      }

      if (shortages.length > 0) {
        await client.query(
          "UPDATE reservations SET status = 'rejected', shortages = $2::jsonb WHERE id = $1",
          [reservationId, JSON.stringify(shortages)],
        );
        return { status: 'REJECTED', shortages };
      }

      for (const productId of productIds) {
        await client.query(
          'UPDATE stock SET quantity = quantity - $2, updated_at = now() WHERE product_id = $1',
          [productId, lines.get(productId)],
        );
      }
      return { status: 'RESERVED', shortages: [] };
    });
  }

  /**
   * Give a reservation's units back. Safe to call any number of times.
   *
   * @param {string} reservationId
   * @returns {Promise<boolean>} true when units were returned by this call
   */
  async release(reservationId) {
    return transaction(this.pool, async (client) => {
      // A release for an id we have never seen leaves a "released" marker,
      // so a Reserve with that id arriving late (after the caller already
      // gave up on it) cannot take stock any more.
      const marker = await client.query(
        `INSERT INTO reservations (id, status, released_at) VALUES ($1, 'released', now())
         ON CONFLICT (id) DO NOTHING
         RETURNING id`,
        [reservationId],
      );

      if (marker.rowCount === 1) {
        return false;
      }

      const { rows } = await client.query(
        'SELECT status FROM reservations WHERE id = $1 FOR UPDATE',
        [reservationId],
      );

      if (rows[0].status !== 'reserved') {
        // Rejected attempts never deducted stock, so there is nothing to return.
        if (rows[0].status === 'rejected') {
          await client.query(
            "UPDATE reservations SET status = 'released', shortages = '[]'::jsonb, released_at = now() WHERE id = $1",
            [reservationId],
          );
        }
        return false;
      }

      const items = await client.query(
        `SELECT product_id, quantity FROM reservation_items
         WHERE reservation_id = $1
         ORDER BY product_id`,
        [reservationId],
      );

      for (const item of items.rows) {
        await client.query(
          `INSERT INTO stock (product_id, quantity) VALUES ($1, $2)
           ON CONFLICT (product_id)
           DO UPDATE SET quantity = stock.quantity + EXCLUDED.quantity, updated_at = now()`,
          [item.product_id, item.quantity],
        );
      }

      await client.query(
        "UPDATE reservations SET status = 'released', released_at = now() WHERE id = $1",
        [reservationId],
      );

      return true;
    });
  }

  /**
   * @param {number} productId
   * @param {number} quantity  absolute stock, >= 0
   * @returns {Promise<{product_id: number, available: number}>}
   */
  async setStock(productId, quantity) {
    const { rows } = await this.pool.query(
      `INSERT INTO stock (product_id, quantity) VALUES ($1, $2)
       ON CONFLICT (product_id)
       DO UPDATE SET quantity = EXCLUDED.quantity, updated_at = now()
       RETURNING product_id, quantity`,
      [productId, quantity],
    );

    return { product_id: Number(rows[0].product_id), available: rows[0].quantity };
  }
}

export class ReservationConflict extends Error {}

/**
 * Two lines for the same product count as one line with the summed quantity.
 *
 * @param {{product_id: number, quantity: number}[]} items
 * @returns {Map<number, number>}
 */
function mergeLines(items) {
  const lines = new Map();

  for (const { product_id: productId, quantity } of items) {
    lines.set(productId, (lines.get(productId) ?? 0) + quantity);
  }

  return lines;
}
