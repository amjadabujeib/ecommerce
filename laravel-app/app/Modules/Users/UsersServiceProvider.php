<?php

namespace App\Modules\Users;

use App\Modules\ModuleServiceProvider;
use App\Modules\Users\Http\Middleware\EnsureUserIsAdmin;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;

final class UsersServiceProvider extends ModuleServiceProvider
{
    public function boot(Router $router): void
    {
        // Stored polymorphic types (e.g. notifications.notifiable_type) use a
        // stable alias instead of the class name, so moving the model never
        // orphans existing rows.
        Relation::morphMap([
            'user' => User::class,
        ]);

        $router->aliasMiddleware('admin', EnsureUserIsAdmin::class);

        $this->loadApiRoutes(__DIR__.'/routes.php');
    }
}
