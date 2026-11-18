<?php

namespace Tests\Feature\Messaging;

use App\Modules\Notifications\Mail\OrderConfirmation;
use App\Modules\Notifications\Services\DeliveryLedger;
use App\Modules\Notifications\Services\OrderEventDelivery;
use App\Modules\Users\Models\User;
use App\Support\Messaging\MessageBroker;
use App\Support\Messaging\Outbox;
use Illuminate\Contracts\Broadcasting\Factory as Broadcasters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function event(int $customerId = 1): array
    {
        return [
            'schema_version' => 1, 'event_id' => (string) Str::uuid(), 'type' => 'order.created',
            'occurred_at' => '2026-11-17T12:00:00Z', 'order_id' => 71, 'order_revision' => 1,
            'customer_id' => $customerId,
            'order' => ['id' => 71, 'revision' => 1, 'status' => 'pending', 'status_label' => 'Pending',
                'total_amount' => '24.50', 'items' => [['product_id' => 12, 'quantity' => 2, 'unit_price' => '12.25']]],
        ];
    }

    public function test_failed_publication_remains_recoverable_and_reuses_event_identity(): void
    {
        config(['messaging.enabled' => true]);
        $broker = new class implements MessageBroker
        {
            public bool $fail = true;

            public array $events = [];

            public function publishEvent(array $event): void
            {
                $this->events[] = $event['event_id'];
                if ($this->fail) {
                    throw new RuntimeException('provider secret must not be retained');
                }
            }
        };
        $outbox = new Outbox($broker);
        $event = $this->event();
        DB::transaction(fn () => $outbox->record($event));
        $this->assertSame(0, $outbox->publishPending($event['event_id']));
        $this->assertDatabaseHas('message_outbox', ['event_id' => $event['event_id'], 'published_at' => null, 'last_error' => RuntimeException::class]);
        $broker->fail = false;
        $this->travel(5)->seconds();
        $this->assertSame(1, $outbox->publishPending());
        $this->assertSame([$event['event_id'], $event['event_id']], $broker->events);
        $this->assertSame(0, $outbox->publishPending());
    }

    public function test_rolled_back_event_is_not_published(): void
    {
        config(['messaging.enabled' => true]);
        $broker = Mockery::mock(MessageBroker::class);
        $broker->shouldNotReceive('publishEvent');
        $outbox = new Outbox($broker);
        try {
            DB::transaction(function () use ($outbox): void {
                $outbox->record($this->event());
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, $outbox->publishPending());
        $this->assertDatabaseCount('message_outbox', 0);
    }

    public function test_successful_delivery_is_not_repeated_for_broker_redelivery(): void
    {
        $ledger = new DeliveryLedger;
        $event = $this->event();
        $sends = 0;
        $send = function () use (&$sends): void {
            $sends++;
        };
        $this->assertSame('ack', $ledger->deliver($event, 'mail', 0, $send)['action']);
        $this->assertSame('ack', $ledger->deliver($event, 'mail', 0, $send)['action']);
        $this->assertSame(1, $sends);
    }

    public function test_ambiguous_retry_handoff_does_not_retry_the_send_or_consume_extra_attempts(): void
    {
        $ledger = new DeliveryLedger;
        $event = $this->event();
        $sends = 0;
        $send = function () use (&$sends): void {
            $sends++;
            throw new RuntimeException('transient');
        };
        $first = $ledger->deliver($event, 'mail', 0, $send);
        $redelivery = $ledger->deliver($event, 'mail', 0, $send);
        $this->assertSame($first, $redelivery);
        $this->assertSame(1, $sends);
        $this->assertSame(5, $first['delay']);
        $ledger->transferred($event, 'mail', 0, $first);
        $ledger->deliver($event, 'mail', 0, $send);
        $this->assertSame(1, $sends);
    }

    public function test_four_failures_are_isolated_for_dead_letter_transfer(): void
    {
        $ledger = new DeliveryLedger;
        $event = $this->event();
        $sends = 0;
        $send = function () use (&$sends): void {
            $sends++;
            throw new RuntimeException('permanent');
        };
        foreach ([5, 30, 120] as $generation => $delay) {
            $plan = $ledger->deliver($event, 'mail', $generation, $send);
            $this->assertSame($delay, $plan['delay']);
            $ledger->transferred($event, 'mail', $generation, $plan);
        }
        $plan = $ledger->deliver($event, 'mail', 3, $send);
        $this->assertSame('dlq', $plan['action']);
        $ledger->transferred($event, 'mail', 3, $plan);
        $this->assertSame('ack', $ledger->deliver($event, 'mail', 3, $send)['action']);
        $this->assertSame(4, $sends);
        $this->assertSame('ack', $ledger->deliver($this->event(), 'mail', 0, fn () => null)['action']);
    }

    public function test_explicit_terminal_replay_is_recoverable_and_does_not_replay_successful_mail(): void
    {
        $ledger = new DeliveryLedger;
        $event = $this->event();
        for ($generation = 0; $generation < 4; $generation++) {
            $plan = $ledger->deliver($event, 'mail', $generation, fn () => throw new RuntimeException('failure'));
            $ledger->transferred($event, 'mail', $generation, $plan);
        }
        $generation = $ledger->prepareReplay($event, 'mail');
        $this->assertSame(4, $generation);
        // A failed/ambiguous broker publication reuses the pending replay identity.
        $this->assertSame($generation, $ledger->prepareReplay($event, 'mail'));
        $ledger->replayPublished($event, 'mail', $generation);
        $this->assertSame('ack', $ledger->deliver($event, 'mail', $generation, fn () => null)['action']);
        $this->expectException(RuntimeException::class);
        $ledger->prepareReplay($event, 'mail');
    }

    public function test_confirmation_uses_primary_directory_recipient_and_committed_line_prices(): void
    {
        Mail::fake();
        $customer = User::factory()->create(['email' => 'controlled@example.test']);
        config(['messaging.mail_allowlist' => ['controlled@example.test']]);
        $event = $this->event($customer->id);
        app(OrderEventDelivery::class)->mail($event);
        Mail::assertSent(OrderConfirmation::class, function (OrderConfirmation $mail): bool {
            $this->assertStringContainsString('2 × 12.25', $mail->render());
            $this->assertStringContainsString('24.50', $mail->render());

            return $mail->hasTo('controlled@example.test');
        });
    }

    public function test_uncontrolled_recipient_is_a_failure_without_send(): void
    {
        Mail::fake();
        $customer = User::factory()->create();
        config(['messaging.mail_allowlist' => []]);
        try {
            app(OrderEventDelivery::class)->mail($this->event($customer->id));
            $this->fail('Expected a blocked recipient.');
        } catch (RuntimeException) {
            Mail::assertNothingSent();
        }
    }

    public function test_repeated_broadcast_processing_does_not_duplicate_database_inbox_entries(): void
    {
        $customer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $broadcaster = Mockery::mock();
        $broadcaster->shouldReceive('broadcast')->twice()->with(['private-admin.orders'], 'order.created', Mockery::on(fn ($payload) => ! isset($payload['customer_id'])));
        $factory = Mockery::mock(Broadcasters::class);
        $factory->shouldReceive('connection')->with('reverb')->twice()->andReturn($broadcaster);
        $this->app->instance(Broadcasters::class, $factory);
        $delivery = app(OrderEventDelivery::class);
        $event = $this->event($customer->id);
        $delivery->realtime($event);
        $delivery->realtime($event);
        $this->assertSame(1, $customer->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_broker_dead_lettered_delivery_can_be_replayed_without_a_ledger_row(): void
    {
        $ledger = new DeliveryLedger;
        $event = $this->event();
        $generation = $ledger->prepareReplay($event, 'mail', 0);
        $this->assertSame(1, $generation);
        $ledger->replayPublished($event, 'mail', $generation);
        $this->assertSame('ack', $ledger->deliver($event, 'mail', $generation, fn () => null)['action']);
        $this->expectException(RuntimeException::class);
        $ledger->prepareReplay($event, 'mail', $generation);
    }

    public function test_broker_replay_cannot_take_over_an_active_delivery_lease(): void
    {
        $ledger = new DeliveryLedger;
        $event = $this->event();
        DB::table('notification_deliveries')->insert([
            'event_id' => $event['event_id'], 'handler' => 'mail', 'recipient_id' => $event['customer_id'],
            'state' => 'processing', 'generation' => 0, 'attempts' => 1,
            'leased_until' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $ledger->prepareReplay($event, 'mail', 0);
            $this->fail('An active delivery lease must be retained.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('notification_deliveries', ['event_id' => $event['event_id'], 'state' => 'processing', 'generation' => 0]);
        }
        $this->travel(2)->minutes();
        $this->assertSame(1, $ledger->prepareReplay($event, 'mail', 0));
    }

    public function test_pruning_is_bounded_and_preserves_unpublished_work_and_delivery_identities(): void
    {
        $old = now()->subDays(10);
        foreach ([null, $old, $old, now()] as $published) {
            $event = $this->event();
            DB::transaction(fn () => app(Outbox::class)->record($event));
            DB::table('message_outbox')->where('event_id', $event['event_id'])->update(['published_at' => $published, 'created_at' => $old]);
        }
        (new DeliveryLedger)->deliver($this->event(), 'mail', 0, fn () => null);
        $this->artisan('messaging:prune', ['--days' => 7, '--limit' => 1])->assertSuccessful();
        $this->assertDatabaseCount('message_outbox', 3);
        $this->assertSame(1, DB::table('message_outbox')->whereNull('published_at')->count());
        $this->assertDatabaseCount('notification_deliveries', 1);
    }
}
