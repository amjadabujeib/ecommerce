<?php

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Data\ReservationStatus;
use App\Modules\Inventory\Exceptions\InventoryUnavailableException;
use App\Modules\Inventory\Grpc\GrpcInventoryGateway;
use App\Modules\Inventory\Grpc\Proto\GetStockResponse;
use App\Modules\Inventory\Grpc\Proto\InventoryServiceClient;
use App\Modules\Inventory\Grpc\Proto\ReservationStatus as ProtoReservationStatus;
use App\Modules\Inventory\Grpc\Proto\ReserveRequest;
use App\Modules\Inventory\Grpc\Proto\ReserveResponse;
use App\Modules\Inventory\Grpc\Proto\Shortage;
use App\Modules\Inventory\Grpc\Proto\StockLevel;
use Google\Protobuf\Internal\Message;
use Grpc\UnaryCall;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * How gRPC answers are translated for the rest of the application. The client
 * is mocked, so these tests need neither the grpc extension nor the service.
 */
final class GrpcInventoryGatewayTest extends TestCase
{
    public function test_every_call_carries_the_deadline_in_microseconds(): void
    {
        $client = $this->createMock(InventoryServiceClient::class);
        $client->expects($this->once())
            ->method('GetStock')
            ->with($this->anything(), [], ['timeout' => 2_000_000])
            ->willReturn($this->answer(new GetStockResponse(['levels' => [
                new StockLevel(['product_id' => 7, 'available' => 3]),
            ]])));

        $levels = $this->gateway($client, timeoutMs: 2000)->availability([7, 8]);

        $this->assertSame([7 => 3, 8 => 0], $levels);
    }

    public function test_reserve_sends_every_line_in_one_request_and_maps_a_rejection(): void
    {
        $client = $this->createMock(InventoryServiceClient::class);
        $client->expects($this->once())
            ->method('Reserve')
            ->with($this->callback(function (ReserveRequest $request): bool {
                $this->assertSame('3f1c4a8e-2b7d-4c55-9a10-6f0e8d2b1a77', $request->getReservationId());
                $this->assertCount(2, $request->getItems());

                return true;
            }))
            ->willReturn($this->answer(new ReserveResponse([
                'status' => ProtoReservationStatus::RESERVATION_STATUS_REJECTED,
                'shortages' => [new Shortage(['product_id' => 2, 'requested' => 4, 'available' => 1])],
            ])));

        $result = $this->gateway($client)->reserve('3f1c4a8e-2b7d-4c55-9a10-6f0e8d2b1a77', [1 => 2, 2 => 4]);

        $this->assertSame(ReservationStatus::Rejected, $result->status);
        $this->assertSame(2, $result->shortages[0]->productId);
        $this->assertSame(1, $result->shortages[0]->available);
    }

    public function test_an_expired_deadline_becomes_inventory_unavailable(): void
    {
        $client = $this->createMock(InventoryServiceClient::class);
        $client->method('Release')->willReturn($this->answer(null, code: 4, details: 'Deadline Exceeded'));

        $this->expectException(InventoryUnavailableException::class);
        $this->expectExceptionMessage(InventoryUnavailableException::MESSAGE);

        $this->gateway($client)->release('3f1c4a8e-2b7d-4c55-9a10-6f0e8d2b1a77');
    }

    public function test_a_stopped_service_becomes_inventory_unavailable(): void
    {
        $client = $this->createMock(InventoryServiceClient::class);
        $client->method('SetStock')->willReturn($this->answer(null, code: 14, details: 'failed to connect to all addresses'));

        try {
            $this->gateway($client)->setStock(1, 5);
            $this->fail('Expected InventoryUnavailableException');
        } catch (InventoryUnavailableException $exception) {
            $this->assertStringContainsString('gRPC status 14', $exception->reason);
        }
    }

    public function test_a_missing_grpc_extension_becomes_inventory_unavailable(): void
    {
        $gateway = new GrpcInventoryGateway(
            fn (): never => throw new RuntimeException('the grpc PHP extension is not installed'),
            2000,
        );

        $this->expectException(InventoryUnavailableException::class);

        $gateway->availability([1]);
    }

    public function test_a_refused_request_is_a_bug_not_an_outage(): void
    {
        $client = $this->createMock(InventoryServiceClient::class);
        $client->method('SetStock')->willReturn($this->answer(null, code: 3, details: 'Invalid product id'));

        $this->expectException(InvalidArgumentException::class);

        $this->gateway($client)->setStock(1, 5);
    }

    private function gateway(InventoryServiceClient $client, int $timeoutMs = 2000): GrpcInventoryGateway
    {
        return new GrpcInventoryGateway(fn (): InventoryServiceClient => $client, $timeoutMs);
    }

    private function answer(?Message $response, int $code = 0, string $details = ''): UnaryCall
    {
        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, (object) ['code' => $code, 'details' => $details]]);

        return $call;
    }
}
