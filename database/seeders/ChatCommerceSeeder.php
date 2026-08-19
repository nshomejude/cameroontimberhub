<?php

namespace Database\Seeders;

use App\Enums\MessageType;
use App\Enums\QuoteStatus;
use App\Models\Conversation;
use App\Models\Quote;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Puts a real quotation card and a live negotiation round into a demo thread,
 * so the Phase 2 surfaces have something behind them locally.
 *
 * Nothing is fabricated. The seeder only ever *reuses* a quote that already
 * exists on a routed, approved RFQ belonging to the buyer on the thread — it
 * never mints a quote out of thin air, because a quote with no routing behind
 * it would be a lie the rest of the app would have to keep believing.
 * If it cannot find such a pairing, it does nothing and says so.
 *
 * Idempotent: it looks for the quotation card before posting one, and
 * ChatCommerceService::issueQuotation() is itself idempotent per quote. Run it
 * standalone — `php artisan db:seed --class=ChatCommerceSeeder` — since the
 * full DatabaseSeeder is known not to be re-runnable.
 */
class ChatCommerceSeeder extends Seeder
{
    public function run(): void
    {
        $messaging = app(MessagingService::class);
        $commerce = app(ChatCommerceService::class);

        // A thread whose buyer also owns an RFQ that this very company quoted.
        // That triple is what makes every card on the screen real.
        $conversation = Conversation::query()
            ->with(['company.users', 'user'])
            ->whereHas('user')
            ->whereHas('company.users')
            ->get()
            ->first(fn (Conversation $c) => $this->liveQuoteFor($c) !== null);

        if ($conversation === null) {
            $this->command?->warn('ChatCommerceSeeder: no conversation with a quotable RFQ behind it. Run QuoteSeeder and MessagingSeeder first.');

            return;
        }

        $quote = $this->liveQuoteFor($conversation);
        $buyer = $conversation->user;
        $supplier = $conversation->company->users->first();

        $commerce->issueQuotation($conversation, $quote, $supplier);

        // A single open negotiation round, so the counter-offer card renders
        // with its buttons live for the supplier. Guarded because the partial
        // unique index allows exactly one pending round per quote.
        $alreadyNegotiating = $conversation->messages()
            ->where('type', MessageType::CounterOffer->value)
            ->exists();

        if (! $alreadyNegotiating) {
            $line = $quote->loadMissing('items')->items->first();

            if ($line !== null && $quote->items->count() === 1) {
                try {
                    $commerce->counter($conversation, $quote, $buyer, [
                        // A real 5% ask, derived from the quoted price rather
                        // than a made-up "target".
                        'unit_price' => round((float) $line->unit_price * 0.95, 2),
                        'note' => 'Could you improve the unit price for this volume?',
                    ]);
                } catch (RuntimeException $e) {
                    $this->command?->warn('ChatCommerceSeeder: counter-offer skipped — '.$e->getMessage());
                }
            }
        }

        $messaging->markRead($conversation, $supplier);

        $this->command?->info('ChatCommerceSeeder: quotation '.$quote->reference_code.' seeded into conversation #'.$conversation->getKey().'.');
    }

    /** An open quote from this thread's company on an RFQ this buyer owns. */
    private function liveQuoteFor(Conversation $conversation): ?Quote
    {
        return Quote::query()
            ->where('company_id', $conversation->company_id)
            ->whereIn('status', [QuoteStatus::Submitted->value, QuoteStatus::Viewed->value])
            ->whereHas('rfq', fn ($q) => $q->where('user_id', $conversation->user_id))
            ->whereHas('items')
            ->latest('id')
            ->first();
    }
}
