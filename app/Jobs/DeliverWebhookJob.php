<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Delivers one webhook event to one subscription, with a fixed 3-attempt
 * backoff schedule (architecture plan, Task 0.5): attempt 1 immediate,
 * attempt 2 after 1 minute, attempt 3 after 10 minutes. After the 3rd
 * failed attempt the delivery is marked dead (`failed_permanently_at`) and
 * never retried again — self-dispatches the next attempt via a fresh job
 * instance with `delay()` rather than `$this->release()`, since a retry
 * here also needs a new/updated WebhookDelivery row, not just a requeue of
 * the same job payload.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry/backoff is fully self-managed (see RETRY_DELAY_SECONDS + the
     * self-dispatch at the end of handle()), so the Laravel queue worker must
     * NOT re-run this job on top of that. One worker attempt per dispatched
     * instance; a thrown exception here is a real bug, not a transient blip.
     */
    public int $tries = 1;

    private const MAX_ATTEMPTS = 3;

    /** Delay (in seconds) before each retry, keyed by the attempt number that just failed. */
    private const RETRY_DELAY_SECONDS = [
        1 => 60,       // attempt 1 failed -> attempt 2 after 1 minute
        2 => 600,      // attempt 2 failed -> attempt 3 after 10 minutes
    ];

    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly int $attempt = 1,
        public readonly ?int $deliveryId = null,
    ) {}

    public function handle(WebhookDeliveryService $service): void
    {
        $subscription = WebhookSubscription::query()->find($this->subscriptionId);

        if ($subscription === null || ! $subscription->is_active) {
            return;
        }

        $delivery = $this->deliveryId !== null
            ? WebhookDelivery::query()->find($this->deliveryId)
            : null;

        if ($delivery === null) {
            $delivery = WebhookDelivery::query()->create([
                'subscription_id' => $subscription->id,
                'event_type' => $this->eventType,
                // ULID generated exactly once, here at row creation — every
                // retry loads this same row (by deliveryId) so the delivered
                // envelope `id` stays stable across all 3 attempts.
                'event_id' => (string) Str::ulid(),
                'payload' => $this->payload,
                'attempt' => 0,
            ]);
        }

        $responseCode = $service->deliver($subscription, $delivery);
        $succeeded = $responseCode !== null && $responseCode >= 200 && $responseCode < 300;

        $delivery->update([
            'attempt' => $this->attempt,
            'response_code' => $responseCode,
        ]);

        if ($succeeded) {
            $delivery->update(['delivered_at' => now()]);

            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            $delivery->update(['failed_permanently_at' => now()]);

            return;
        }

        $delaySeconds = self::RETRY_DELAY_SECONDS[$this->attempt] ?? 600;

        self::dispatch(
            $this->subscriptionId,
            $this->eventType,
            $this->payload,
            $this->attempt + 1,
            $delivery->id,
        )->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * An unhandled exception (as opposed to a non-2xx response, which is
     * handled above) — DB unavailable, a bug in WebhookDeliveryService, etc.
     * The self-managed retry chain is broken at this point, so surface it.
     */
    public function failed(?\Throwable $e): void
    {
        Log::channel('errors')->error('DeliverWebhookJob failed permanently.', [
            'job' => self::class,
            'subscription_id' => $this->subscriptionId,
            'event_type' => $this->eventType,
            'attempt' => $this->attempt,
            'delivery_id' => $this->deliveryId,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }
}
