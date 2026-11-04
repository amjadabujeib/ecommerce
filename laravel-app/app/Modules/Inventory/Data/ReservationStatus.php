<?php

namespace App\Modules\Inventory\Data;

enum ReservationStatus: string
{
    /** Every line was reserved; the stock has been taken. */
    case Reserved = 'reserved';

    /** At least one line could not be served; nothing was taken. */
    case Rejected = 'rejected';

    /** The reservation id was already released, so nothing was taken. */
    case Released = 'released';
}
