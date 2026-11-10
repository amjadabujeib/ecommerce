<?php

namespace App\Modules\Inventory;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Inventory\Grpc\GrpcInventoryGateway;
use App\Modules\Inventory\Grpc\Proto\InventoryServiceClient;
use Grpc\ChannelCredentials;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The client side of the inventory service. This module has no routes and no
 * tables: stock lives in the inventory service's own database, reached only
 * through the gRPC contract in proto/inventory/v1/inventory.proto.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InventoryGateway::class, fn (): InventoryGateway => new GrpcInventoryGateway(
            clientFactory: function (): InventoryServiceClient {
                if (! extension_loaded('grpc')) {
                    throw new RuntimeException('the grpc PHP extension is not installed');
                }

                return new InventoryServiceClient(
                    (string) config('services.inventory.address'),
                    [
                        'credentials' => ChannelCredentials::createInsecure(),
                        // PHP otherwise reuses persistent channels across requests,
                        // including stale DNS after a container changes address.
                        'force_new' => true,
                        'grpc.use_local_subchannel_pool' => 1,
                    ],
                );
            },
            timeoutMs: (int) config('services.inventory.timeout_ms'),
        ));
    }
}
