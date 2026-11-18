<?php

namespace Tests\Feature\Messaging;

use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use App\Support\Realtime\RealtimeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class RealtimeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.enabled' => true,
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-signing-secret']);
    }

    public function test_order_channel_authorizes_only_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $channel = 'private-orders.'.$order->id;
        $token = Auth::guard('api')->login($owner);
        $this->withToken($token)->postJson('/api/v1/realtime/auth', ['socket_id' => '12.34', 'channel_name' => $channel])
            ->assertOk()->assertJsonPath('auth', 'test-key:'.hash_hmac('sha256', '12.34:'.$channel, 'test-signing-secret'));
        $this->assertTrue(app(RealtimeAccess::class)->allows('12.34', $channel));
        Auth::forgetGuards();
        $token = Auth::guard('api')->login($other);
        $this->withToken($token)->postJson('/api/v1/realtime/auth', ['socket_id' => '56.78', 'channel_name' => $channel])->assertForbidden();
        $this->assertFalse(app(RealtimeAccess::class)->allows('56.78', $channel));
    }

    public function test_live_delivery_rechecks_revocation_after_subscription(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $channel = 'private-orders.'.$order->id;
        $token = Auth::guard('api')->login($owner);
        $this->withToken($token)->postJson('/api/v1/realtime/auth', ['socket_id' => '12.34', 'channel_name' => $channel])->assertOk();
        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
        $this->assertFalse(app(RealtimeAccess::class)->allows('12.34', $channel));
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/realtime/auth', ['socket_id' => '12.34', 'channel_name' => $channel])->assertUnauthorized();
    }

    public function test_administrator_role_is_rechecked_for_existing_subscription(): void
    {
        $admin = User::factory()->admin()->create();
        $token = Auth::guard('api')->login($admin);
        $this->withToken($token)->postJson('/api/v1/realtime/auth', ['socket_id' => '12.34', 'channel_name' => 'private-admin.orders'])->assertOk();
        $this->assertTrue(app(RealtimeAccess::class)->allows('12.34', 'private-admin.orders'));
        $admin->forceFill(['role' => 'customer'])->save();
        $this->assertFalse(app(RealtimeAccess::class)->allows('12.34', 'private-admin.orders'));
    }

    public function test_guests_and_unknown_or_public_channels_are_rejected(): void
    {
        $this->postJson('/api/v1/realtime/auth', ['socket_id' => '12.34', 'channel_name' => 'private-admin.orders'])->assertUnauthorized();
        $customer = User::factory()->create();
        $token = Auth::guard('api')->login($customer);
        foreach (['private-admin.orders', 'orders.1', 'private-orders.01', 'private-unknown'] as $channel) {
            Auth::forgetGuards();
            $this->withToken($token)->postJson('/api/v1/realtime/auth', ['socket_id' => '12.34', 'channel_name' => $channel])->assertForbidden();
        }
    }

    public function test_config_exposes_only_public_settings(): void
    {
        $response = $this->getJson('/api/v1/realtime/config')->assertOk()->assertJsonPath('data.key', 'test-key');
        $this->assertStringNotContainsString('test-signing-secret', $response->getContent());
        $this->assertFalse(app(RealtimeAccess::class)->allows('12.34', 'private-orders.1'));
    }
}
