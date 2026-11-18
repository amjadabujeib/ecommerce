<?php

namespace Tests\Feature\Messaging;

use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use App\Support\Messaging\MessageBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrderEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.enabled' => true, 'messaging.publish_immediately' => false]);
    }

    public function test_checkout_records_committed_prices_and_never_sends_mail_in_the_request(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(10)->create(['price' => '12.50']);
        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();
        $id = $this->postJson(route('orders.store'))->assertCreated()
            ->assertJsonPath('data.revision', 1)->assertJsonPath('data.total_amount', '25.00')->json('data.id');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('message_outbox', 1);
        $row = DB::table('message_outbox')->first();
        $event = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('order.created', $event['type']);
        $this->assertSame($id, $event['order_id']);
        $this->assertSame($customer->id, $event['customer_id']);
        $this->assertSame('12.50', $event['order']['items'][0]['unit_price']);
        $this->assertSame(2, $event['order']['items'][0]['quantity']);
        $this->assertNull($row->published_at);
    }

    public function test_failed_outbox_insert_rolls_back_order_and_compensates_inventory(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();
        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();
        DB::statement("CREATE TRIGGER reject_event BEFORE INSERT ON message_outbox BEGIN SELECT RAISE(ABORT, 'injected persistence failure'); END");
        try {
            $this->postJson(route('orders.store'))->assertServerError();
            $this->assertDatabaseCount('orders', 0);
            $this->assertSame(10, $this->inventory->stockOf($product));
            $this->getJson(route('cart.index'))->assertJsonPath('data.item_count', 2);
        } finally {
            DB::statement('DROP TRIGGER reject_event');
        }
    }

    public function test_broker_failure_after_commit_preserves_201_and_pending_event(): void
    {
        $this->app->instance(MessageBroker::class, new class implements MessageBroker
        {
            public function publishEvent(array $event): void
            {
                throw new \RuntimeException('broker unavailable');
            }
        });
        config(['messaging.publish_immediately' => true]);
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(4)->create();
        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 1])->assertOk();
        $this->postJson(route('orders.store'))->assertCreated();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('message_outbox', 1);
        $this->assertNull(DB::table('message_outbox')->value('published_at'));
        $this->assertSame(3, $this->inventory->stockOf($product));
    }

    public function test_real_status_changes_advance_revision_and_no_op_does_not_emit(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create();
        $this->actingAs($admin)->patchJson(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertDatabaseCount('message_outbox', 1);
        $event = json_decode(DB::table('message_outbox')->value('payload'), true);
        $this->assertSame('order.status_changed', $event['type']);
        $this->assertSame('pending', $event['previous_status']);
        $this->assertSame(2, $event['order_revision']);
    }
}
