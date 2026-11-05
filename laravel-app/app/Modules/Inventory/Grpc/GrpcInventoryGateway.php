<?php

namespace App\Modules\Inventory\Grpc;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Inventory\Data\ReservationResult;
use App\Modules\Inventory\Data\Shortage;
use App\Modules\Inventory\Exceptions\InventoryUnavailableException;
use App\Modules\Inventory\Grpc\Proto\GetStockRequest;
use App\Modules\Inventory\Grpc\Proto\GetStockResponse;
use App\Modules\Inventory\Grpc\Proto\InventoryServiceClient;
use App\Modules\Inventory\Grpc\Proto\ReleaseRequest;
use App\Modules\Inventory\Grpc\Proto\ReleaseResponse;
use App\Modules\Inventory\Grpc\Proto\ReservationStatus as ProtoReservationStatus;
use App\Modules\Inventory\Grpc\Proto\ReserveItem;
use App\Modules\Inventory\Grpc\Proto\ReserveRequest;
use App\Modules\Inventory\Grpc\Proto\ReserveResponse;
use App\Modules\Inventory\Grpc\Proto\SetStockRequest;
use App\Modules\Inventory\Grpc\Proto\SetStockResponse;
use Closure;
use Google\Protobuf\Internal\Message;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Talks to the inventory service over gRPC.
 *
 * Every call carries a deadline: if the service has not answered in time the
 * call is abandoned and the user gets a clear 503 instead of waiting forever.
 */
final class GrpcInventoryGateway implements InventoryGateway
{
    /**
     * gRPC status codes used here (https://grpc.io/docs/guides/status-codes/).
     */
    private const int STATUS_OK = 0;

    private const int STATUS_INVALID_ARGUMENT = 3;

    private const int STATUS_DEADLINE_EXCEEDED = 4;

    private ?InventoryServiceClient $client = null;

    /**
     * The client is created on first use, so the application still boots
     * (and can show products) when the grpc extension is missing.
     *
     * @param  Closure(): InventoryServiceClient  $clientFactory
     * @param  int  $timeoutMs  deadline of every call
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly int $timeoutMs,
    ) {}

    public function availability(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        /** @var GetStockResponse $response */
        $response = $this->call('GetStock', new GetStockRequest(['product_ids' => $productIds]));

        $levels = array_fill_keys($productIds, 0);

        foreach ($response->getLevels() as $level) {
            $levels[(int) $level->getProductId()] = $level->getAvailable();
        }

        return $levels;
    }

    public function reserve(string $reservationId, array $quantities): ReservationResult
    {
        $items = [];

        foreach ($quantities as $productId => $quantity) {
            $items[] = new ReserveItem(['product_id' => $productId, 'quantity' => $quantity]);
        }

        /** @var ReserveResponse $response */
        $response = $this->call('Reserve', new ReserveRequest([
            'reservation_id' => $reservationId,
            'items' => $items,
        ]));

        return match ($response->getStatus()) {
            ProtoReservationStatus::RESERVATION_STATUS_RESERVED => ReservationResult::reserved(),
            ProtoReservationStatus::RESERVATION_STATUS_RELEASED => ReservationResult::alreadyReleased(),
            ProtoReservationStatus::RESERVATION_STATUS_REJECTED => ReservationResult::rejected(array_map(
                fn ($shortage): Shortage => new Shortage(
                    (int) $shortage->getProductId(),
                    $shortage->getRequested(),
                    $shortage->getAvailable(),
                ),
                iterator_to_array($response->getShortages(), false),
            )),
            default => throw $this->unavailable('Reserve', 'unknown reservation status '.$response->getStatus()),
        };
    }

    public function release(string $reservationId): bool
    {
        /** @var ReleaseResponse $response */
        $response = $this->call('Release', new ReleaseRequest(['reservation_id' => $reservationId]));

        return $response->getReleased();
    }

    public function setStock(int $productId, int $quantity): int
    {
        /** @var SetStockResponse $response */
        $response = $this->call('SetStock', new SetStockRequest([
            'product_id' => $productId,
            'quantity' => $quantity,
        ]));

        return $response->getLevel()?->getAvailable() ?? $quantity;
    }

    /**
     * Make one unary call with the deadline and turn every failure into an
     * InventoryUnavailableException (or InvalidArgumentException for a request
     * the service refused as malformed, which is a bug on our side).
     */
    private function call(string $method, Message $request): Message
    {
        try {
            [$response, $status] = $this->client()
                ->{$method}($request, [], ['timeout' => $this->timeoutMs * 1000])
                ->wait();
        } catch (InventoryUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable($method, $exception->getMessage(), $exception);
        }

        $code = (int) $status->code;

        if ($code === self::STATUS_OK && $response instanceof Message) {
            return $response;
        }

        if ($code === self::STATUS_INVALID_ARGUMENT) {
            throw new InvalidArgumentException("The inventory service refused {$method}: {$status->details}");
        }

        $reason = $code === self::STATUS_DEADLINE_EXCEEDED
            ? "no answer within {$this->timeoutMs} ms"
            : "gRPC status {$code}: {$status->details}";

        throw $this->unavailable($method, $reason);
    }

    private function client(): InventoryServiceClient
    {
        return $this->client ??= ($this->clientFactory)();
    }

    private function unavailable(string $method, string $reason, ?Throwable $previous = null): InventoryUnavailableException
    {
        Log::warning('Inventory service call failed', ['method' => $method, 'reason' => $reason]);

        return new InventoryUnavailableException("{$method}: {$reason}", $previous);
    }
}
