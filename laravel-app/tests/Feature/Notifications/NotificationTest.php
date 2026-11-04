<?php

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Notifications\NewOrderReceived;
use App\Modules\Notifications\Notifications\OrderPlaced;
use App\Modules\Notifications\Notifications\OrderStatusChanged;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\CartItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_placing_an_order_notifies_the_customer_and_administrators(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(5)->create();
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for($product)
            ->create(['quantity' => 1]);

        $this->actingAs($customer, 'api')
            ->postJson(route('orders.store'))
            ->assertCreated();

        Notification::assertSentTo($customer, OrderPlaced::class);
        Notification::assertSentTo($admin, NewOrderReceived::class);
        Notification::assertNotSentTo($customer, NewOrderReceived::class);
    }

    public function test_status_change_notifies_the_customer_once(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create();

        $this->actingAs($admin, 'api')
            ->patchJson(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertOk();

        // Re-submitting the current status is a no-op and must not notify again.
        $this->actingAs($admin, 'api')
            ->patchJson(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertOk();

        $notifications = $order->user->notifications;

        $this->assertCount(1, $notifications);
        $this->assertSame(OrderStatusChanged::class, $notifications->first()->type);
        $this->assertSame('pending', $notifications->first()->data['previous_status']);
        $this->assertSame('completed', $notifications->first()->data['status']);
    }

    public function test_users_only_see_their_own_notifications(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();

        $customer->notify(new OrderPlaced(Order::factory()->for($customer)->create()));
        $other->notify(new OrderPlaced(Order::factory()->for($other)->create()));

        $this->actingAs($customer, 'api')
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'order_placed')
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_users_cannot_mark_someone_elses_notification(): void
    {
        $owner = User::factory()->create();
        $owner->notify(new OrderPlaced(Order::factory()->for($owner)->create()));
        $notification = $owner->notifications()->firstOrFail();

        $this->actingAs(User::factory()->create(), 'api')
            ->patchJson(route('notifications.read', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_users_can_mark_notifications_as_read(): void
    {
        $customer = User::factory()->create();
        $customer->notify(new OrderPlaced(Order::factory()->for($customer)->create()));
        $customer->notify(new OrderPlaced(Order::factory()->for($customer)->create()));
        $first = $customer->notifications()->firstOrFail();

        $this->actingAs($customer, 'api')
            ->patchJson(route('notifications.read', $first->id))
            ->assertOk()
            ->assertJsonPath('data.id', $first->id);

        $this->actingAs($customer, 'api')
            ->getJson(route('notifications.index', ['unread' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1);

        $this->actingAs($customer, 'api')
            ->postJson(route('notifications.read-all'))
            ->assertOk();

        $this->assertSame(0, $customer->unreadNotifications()->count());
    }
}
