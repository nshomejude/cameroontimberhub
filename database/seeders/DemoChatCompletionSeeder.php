<?php

namespace Database\Seeders;

use App\Enums\MessageType;
use App\Enums\OrderDocumentKind;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Services\ChatCommerceService;
use App\Services\OrderLifecycleService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Fills the three chat card types the other demo seeders never produce:
 * rfq_reference, order_documents and company_review.
 *
 * Everything here goes through the same services the live UI calls
 * (ChatCommerceService::createRfqFromChat, OrderLifecycleService::attachDocuments
 * and ::review) rather than inserting message rows directly, so the demo thread
 * shows cards that were genuinely produced by the real code paths — including
 * their authorization checks and one-per-order constraints.
 *
 * Idempotent: each step is skipped when its card already exists on the thread.
 */
class DemoChatCompletionSeeder extends Seeder
{
    public function run(): void
    {
        $conversation = Conversation::query()
            ->whereHas('messages')
            ->with(['company'])
            ->orderByDesc('last_message_at')
            ->first();

        if ($conversation === null) {
            $this->command?->warn('DemoChatCompletionSeeder: no conversation found — run MessagingSeeder first.');

            return;
        }

        $buyer = $conversation->user;
        $supplier = $conversation->company?->users()->first();

        if ($buyer === null || $supplier === null) {
            $this->command?->warn('DemoChatCompletionSeeder: conversation is missing a buyer or a supplier user.');

            return;
        }

        $this->seedRfqReference($conversation, $buyer);
        $this->seedOrderDocuments($conversation, $supplier);
        $this->seedCompanyReview($conversation, $buyer);
    }

    private function has(Conversation $conversation, MessageType $type): bool
    {
        return Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('type', $type->value)
            ->exists();
    }

    /** A buyer raising a quote request from inside the thread. */
    private function seedRfqReference(Conversation $conversation, $buyer): void
    {
        if ($this->has($conversation, MessageType::RfqReference)) {
            $this->command?->line('rfq_reference card already present — skipped.');

            return;
        }

        try {
            app(ChatCommerceService::class)->createRfqFromChat($conversation, $buyer, [
                'title' => 'Repeat enquiry — Sapele lumber',
                'species_text' => 'Sapele',
                'quantity' => 40,
                'unit' => 'm3',
                'form' => 'sawn',
                'grade' => 'Premium',
                'moisture_content' => 'Kiln dried',
                'incoterm' => 'FOB',
                'shipping_port' => 'Douala',
                'destination_country_code' => 'NL',
                'notes' => 'Please confirm current pricing and the earliest shipping window.',
            ]);

            $this->command?->info('rfq_reference card created.');
        } catch (Throwable $e) {
            $this->command?->warn('rfq_reference skipped: '.$e->getMessage());
        }
    }

    /** A supplier attaching shipping paperwork to the order. */
    private function seedOrderDocuments(Conversation $conversation, $supplier): void
    {
        if ($this->has($conversation, MessageType::OrderDocuments)) {
            $this->command?->line('order_documents card already present — skipped.');

            return;
        }

        $order = $this->orderOnThread($conversation);

        if ($order === null) {
            $this->command?->warn('order_documents skipped: no order on this thread.');

            return;
        }

        // A real (tiny) PDF written to a temp path, uploaded through the same
        // validated storage path the UI uses — not a row poked into the table.
        $tmp = tempnam(sys_get_temp_dir(), 'demo-doc').'.pdf';
        File::put($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        try {
            app(OrderLifecycleService::class)->attachDocuments(
                $conversation,
                $order,
                $supplier,
                [new UploadedFile($tmp, 'packing-list.pdf', 'application/pdf', null, true)],
                OrderDocumentKind::PackingList,
                'Packing list',
            );

            $this->command?->info('order_documents card created.');
        } catch (Throwable $e) {
            $this->command?->warn('order_documents skipped: '.$e->getMessage());
        } finally {
            File::delete($tmp);
        }
    }

    /** The buyer reviewing the supplier after a completed order. */
    private function seedCompanyReview(Conversation $conversation, $buyer): void
    {
        if ($this->has($conversation, MessageType::CompanyReview)) {
            $this->command?->line('company_review card already present — skipped.');

            return;
        }

        // Eligibility is enforced by CompanyReviewService: the order must be
        // completed and owned by this buyer, and one review per order.
        $order = $this->orderOnThread($conversation, OrderStatus::Completed);

        if ($order === null) {
            $this->command?->warn('company_review skipped: no completed order on this thread.');

            return;
        }

        try {
            app(OrderLifecycleService::class)->review($conversation, $order, $buyer, [
                'rating' => 5,
                'title' => 'Straightforward shipment, accurate grading',
                'body' => 'Grading matched the quotation, documents arrived before the vessel and communication was prompt throughout.',
            ]);

            $this->command?->info('company_review card created.');
        } catch (Throwable $e) {
            $this->command?->warn('company_review skipped: '.$e->getMessage());
        }
    }

    private function orderOnThread(Conversation $conversation, ?OrderStatus $status = null): ?Order
    {
        return Order::query()
            ->where('company_id', $conversation->company_id)
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->orderBy('id')
            ->first();
    }
}
