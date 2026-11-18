<?php

namespace App\Modules\Users;

use Illuminate\Auth\EloquentUserProvider;

final class PrimaryUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->useWritePdo();
    }
}
