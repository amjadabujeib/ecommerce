<?php

namespace App\Modules\Users\PublicApi;

/** Authenticated identity exposed without the Users persistence model. */
interface Actor
{
    public function getAuthIdentifier();

    public function isAdmin(): bool;
}
