import { transaction } from './db.js';

/**
 * The business rules of the inventory, independent of gRPC.
 *
 * Every write runs in one database transaction and locks the stock rows it
 * changes (SELECT ... FOR UPDATE, always in product_id order so two requests
 * can never deadlock). That lock is what solves the race condition: when
 * several orders want the last unit at the same moment, they queue on the
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

    const outcome = await transaction(this.pool, async (client) => {
      // Claim the reservation id. If another call already used it (a retry
      // after a timeout, or a Release that arrived first), we answer with
      // what that call decided instead of taking stock twice.
      const claim = await client.query(
        `INSERT INTO reservations (id, status) VALUES ($1, 'reserved')
         ON CONFLICT (id) DO NOTHING
         RETURNING id`,
        [reservationId],
      );

      if (claim.rowCount === 0) {
        return { existing: true };
      }

      const productIds = [...lines.keys()].sort((a, b) => a - b);

      const { rows } = await client.query(
        `SELECT product_id, quantity FROM stock
         WHERE product_id = ANY($1::bigint[])
         ORDER BY product_id
         FOR UPDATE`,
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

      if (shortages.length > 0) {
        // Throwing rolls back the claim as well: a rejected request leaves
        // no trace and may be retried later with the same id.
        throw new RejectedReservation(shortages);
      }

      for (const productId of productIds) {
        const quantity = lines.get(productId);

        await client.query(
          'UPDATE stock SET quantity = quantity - $2, updated_at = now() WHERE product_id = $1',
          [productId, quantity],
        );
        await client.query(
          'INSERT INTO reservation_items (reservation_id, product_id, quantity) VALUES ($1, $2, $3)',
          [reservationId, productId, quantity],
        );
      }

      return { existing: false };
    }).catch((error) => {
      if (error instanceof RejectedReservation) {
        return { rejected: error.shortages };
      }

      throw error;
    });

    if (outcome.rejected) {
      return { status: 'REJECTED', shortages: outcome.rejected };
    }

    if (outcome.existing) {
      const { rows } = await this.pool.query('SELECT status FROM reservations WHERE id = $1', [
        reservationId,
      ]);

      return { status: rows[0]?.status === 'released' ? 'RELEASED' : 'RESERVED', shortages: [] };
    }

    return { status: 'RESERVED', shortages: [] };
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

      if (rows[0].status === 'released') {
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

class RejectedReservation extends Error {
  /** @param {{product_id: number, requested: number, available: number}[]} shortages */
  constructor(shortages) {
    super('Not enough stock');
    this.shortages = shortages;
  }
}

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
