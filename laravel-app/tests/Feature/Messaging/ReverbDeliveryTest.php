<?php

namespace Tests\Feature\Messaging;

use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use App\Support\Realtime\AuthorizedConnections;
use App\Support\Realtime\RealtimeAccess;
use App\Support\Realtime\RealtimeServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Reverb\Application;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\Channels\PrivateChannel;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Tests\TestCase;

/** Exercises Reverb's real channel delivery, beyond the authorization HTTP response. */
final class ReverbDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'messaging.enabled' => true,
            'broadcasting.connections.reverb.key' => 'delivery-key',
            'broadcasting.connections.reverb.secret' => 'delivery-secret',
        ]);
        // Test configuration changes after application boot; run the actual
        // provider binding rather than binding the adapter only in this test.
        (new RealtimeServiceProvider($this->app))->boot();
        $this->assertInstanceOf(AuthorizedConnections::class, $this->app->make(ChannelConnectionManager::class));
    }

    public function test_revoked_socket_is_terminated_without_receiving_the_next_order_payload(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $name = 'private-orders.'.$order->id;
        $token = Auth::guard('api')->login($owner);
        $auth = app(RealtimeAccess::class)->authorize('12.34', $name, $token, $owner->id);
        [$socket, $transport] = $this->socket('12.34');
        $channel = new PrivateChannel($name);
        $channel->subscribe($socket, $auth['auth']);
        $channel->broadcast(['event' => 'order.status_changed', 'data' => ['marker' => 'before-logout']]);

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
        $channel->broadcast(['event' => 'order.status_changed', 'data' => ['marker' => 'after-logout']]);
        $this->assertEmpty($channel->connections());
        $this->assertSame(1, $transport->closes);
        $this->assertCount(1, $transport->messages);
        $this->assertSame('before-logout', json_decode($transport->messages[0], true)['data']['marker']);
    }

    public function test_role_changes_remove_only_the_demoted_admin_from_a_real_broadcast(): void
    {
        $first = User::factory()->admin()->create();
        $second = User::factory()->admin()->create();
        $name = 'private-admin.orders';
        $access = app(RealtimeAccess::class);
        $firstAuth = $access->authorize('12.34', $name, Auth::guard('api')->login($first), $first->id);
        $secondAuth = $access->authorize('56.78', $name, Auth::guard('api')->login($second), $second->id);
        [$firstSocket, $firstTransport] = $this->socket('12.34');
        [$secondSocket, $secondTransport] = $this->socket('56.78');
        $channel = new PrivateChannel($name);
        $channel->subscribe($firstSocket, $firstAuth['auth']);
        $channel->subscribe($secondSocket, $secondAuth['auth']);

        $first->forceFill(['role' => 'customer'])->save();
        $channel->broadcast(['event' => 'order.created', 'data' => ['order_id' => 42]]);
        $this->assertCount(1, $channel->connections());
        $this->assertSame([], $firstTransport->messages);
        $this->assertSame(1, $firstTransport->closes);
        $this->assertCount(1, $secondTransport->messages);
        $this->assertSame('order.created', json_decode($secondTransport->messages[0], true)['event']);
        $this->assertSame(0, $secondTransport->closes);
    }

    public function test_expired_token_cannot_receive_a_real_broadcast(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $name = 'private-orders.'.$order->id;
        $token = Auth::guard('api')->login($owner);
        $auth = app(RealtimeAccess::class)->authorize('12.34', $name, $token, $owner->id);
        [$socket, $transport] = $this->socket('12.34');
        $channel = new PrivateChannel($name);
        $channel->subscribe($socket, $auth['auth']);
        $this->travel(61)->minutes();

        $channel->broadcast(['event' => 'order.status_changed', 'data' => ['order_id' => $order->id]]);
        $this->assertEmpty($channel->connections());
        $this->assertSame([], $transport->messages);
        $this->assertSame(1, $transport->closes);
    }

    /** @return array{Connection, RecordingWebSocket} */
    private function socket(string $id): array
    {
        $application = new Application('delivery-app', 'delivery-key', 'delivery-secret', 30, 30, ['localhost'], 10_000);
        $transport = new RecordingWebSocket($id);

        return [new FixedIdentifierConnection($transport, $application, 'http://localhost'), $transport];
    }
}

/** Real Reverb protocol connection with a deterministic socket ID for signed auth. */
final class FixedIdentifierConnection extends Connection
{
    public function id(): string
    {
        return $this->identifier();
    }
}

/** Captures only the network seam; JWT, subscription and broadcast code are real. */
final class RecordingWebSocket implements WebSocketConnection
{
    public array $messages = [];

    public int $closes = 0;

    public function __construct(private readonly string $identifier) {}

    public function id(): string
    {
        return $this->identifier;
    }

    public function send(mixed $message): void
    {
        $this->messages[] = $message;
    }

    public function close(mixed $message = null): void
    {
        $this->closes++;
    }
}
