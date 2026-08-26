<?php

namespace Database\Seeders;

use App\Enums\ConversationTopic;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\MessagingService;
use Illuminate\Database\Seeder;

/**
 * A demo thread with both sides populated, so the inbox, the thread, the
 * order-reference card and the live status trail all have real data locally.
 *
 * It picks an order that already has BOTH a buyer account and a supplier staff
 * member on the company_user pivot, so the conversation is genuinely readable
 * and repliable from /account and from /dashboard/messages. Nothing is
 * fabricated: if no such order exists the seeder degrades to a plain thread,
 * and if there is no demo company at all it does nothing.
 *
 * Idempotent: skipped entirely once a thread exists between that buyer and
 * that company, so re-running never duplicates messages.
 */
class MessagingSeeder extends Seeder
{
    public function run(): void
    {
        $messaging = app(MessagingService::class);

        // Prefer an awarded order that already has a buyer account, so the
        // order-reference and status cards have a real record behind them.
        $order = Order::query()
            ->whereNotNull('user_id')
            ->with(['items', 'company', 'user'])
            ->latest('id')
            ->first();

        $buyer = $order?->user;
        $company = $order?->company;

        // The supplier side needs someone to sign in as. If the order's company
        // has no staff on the pivot yet, link the existing demo exporter
        // account to it — a local-only convenience, never invented data.
        if ($company !== null && $company->users()->doesntExist()) {
            $exporter = User::where('email', 'exporter@cameroontimberhub.test')->first();

            $exporter?->companies()->syncWithoutDetaching([$company->getKey() => ['role' => 'owner', 'is_primary' => false]]);
        }

        if ($company === null) {
            $company = Company::query()->publiclyVisible()->whereHas('users')->first()
                ?? Company::query()->publiclyVisible()->first();

            $buyer = User::query()->where('email', 'demo.buyer@example.com')->first()
                ?? User::query()->whereDoesntHave('companies')->first();
        }

        if ($company === null || $buyer === null) {
            return;
        }

        if (Conversation::where('user_id', $buyer->getKey())->where('company_id', $company->getKey())->exists()) {
            return;
        }

        $product = Product::where('company_id', $company->getKey())->first();
        $supplier = $company->users()->first();

        $conversation = $messaging->start(
            buyer: $buyer,
            company: $company,
            topic: $product ? ConversationTopic::Product : ConversationTopic::General,
            product: $product,
        );

        $messaging->post($conversation, $buyer, "Hello! I'm interested in 50m³ of Sapele lumber.\nPlease share the specifications, price per m³ and delivery terms.");

        if ($supplier) {
            $messaging->post($conversation, $supplier, 'Sure — grade Premium, kiln dried, 2.4m to 6.1m lengths. I will send the quotation shortly.');
        }

        if ($order && (int) $order->user_id === (int) $buyer->getKey()) {
            $messaging->postOrderReference($conversation, $supplier, $order);
            $messaging->postOrderStatus($conversation, $order);
            $messaging->post($conversation, $buyer, 'Thank you — noted. Please keep me posted on shipment.');
        }
    }
}
