<?php

namespace App\Modules\Users;

use App\Modules\ModuleServiceProvider;
use App\Modules\Users\Http\Middleware\EnsureUserIsAdmin;
use App\Modules\Users\Models\User;
use App\Modules\Users\PublicApi\UserDirectory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;

final class UsersServiceProvider extends ModuleServiceProvider
{
    public function boot(Router $router): void
    {
        Auth::provider('primary-eloquent', fn ($app, $config) => new PrimaryUserProvider($app['hash'], $config['model']));

        // Stored polymorphic types (e.g. notifications.notifiable_type) use a
        // stable alias instead of the class name, so moving the model never
        // orphans existing rows.
        Relation::morphMap([
            UserDirectory::NOTIFIABLE_TYPE => User::class,
        ]);

        $router->aliasMiddleware('admin', EnsureUserIsAdmin::class);

        $this->loadApiRoutes(__DIR__.'/routes.php');
    }
}
