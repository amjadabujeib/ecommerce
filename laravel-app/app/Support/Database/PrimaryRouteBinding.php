<?php

namespace App\Support\Database;

/** Existence and ownership checks must see authoritative records. */
trait PrimaryRouteBinding
{
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->useWritePdo();
    }
}
