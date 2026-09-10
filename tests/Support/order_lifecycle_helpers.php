<?php

/*
 * Shared order-lifecycle scene builders.
 *
 * `olScene()` / `olAdvanceTo()` / `olCard()` / `olLifecycle()` are used by more
 * than one feature file (OrderChatLifecycleTest, CompanyReviewTest, ...). They
 * live here — loaded via composer's `autoload-dev.files` — rather than at the
 * top of one test file, because `php artisan test --parallel` shards test
 * files across worker processes: a helper defined in file A is NOT visible to
 * file B when Paratest puts them in different workers. An autoloaded file is
 * present in every worker.
 *
 * The `ol` prefix is kept so these never collide with a same-named helper in
 * some other suite.
 */

use App\Enums\CompanyUserRole;
use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use App\Services\OrderLifecycleService;

if (! function_exists('olLifecycle')) {
    function olLifecycle(): OrderLifecycleService
    {
        return app(OrderLifecycleService::class);
    }
}

if (! function_exists('olScene')) {
    /**
     * A whole delivered-ready situation: buyer, supplier company + staff member,
     * an accepted quote, the resulting order, and the conversation they live in.
     *
     * @return array{0: Conversation, 1: User, 2: Company, 3: User, 4: Order}
     */
    function olScene(): array
    {
        $company = Company::factory()->publiclyVisible()->create();
        $staff = User::factory()->create(['email' => 'olstaff'.uniqid().'@example.com']);
        $company->users()->attach($staff, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

        $buyer = User::factory()->create([
            'email' => 'olbuyer'.uniqid().'@example.com',
            'email_verified_at' => now(),
        ]);

        $rfq = Rfq::factory()->approved()->create([
            'user_id' => $buyer->getKey(),
            'buyer_email' => $buyer->email,
        ]);
        $rfqItem = $rfq->items()->create([
            'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3',
        ]);

        $routing = RfqCompany::create([
            'rfq_id' => $rfq->getKey(),
            'company_id' => $company->getKey(),
            'status' => 'sent',
            'routed_at' => now(),
        ]);

        $quote = Quote::factory()->submitted()->create([
            'rfq_id' => $rfq->getKey(),
            'company_id' => $company->getKey(),
            'rfq_company_id' => $routing->getKey(),
            'currency' => 'USD',
            'incoterm' => 'CIF',
            'lead_time_days' => 10,
            'validity_days' => 15,
            'valid_until' => now()->addDays(15)->toDateString(),
            'payment_terms' => '50% advance, 50% before shipment',
        ]);

        QuoteItem::factory()->create([
            'quote_id' => $quote->getKey(),
            'rfq_item_id' => $rfqItem->getKey(),
            'description' => 'Premium Sapele Lumber (KD)',
            'quantity' => 50,
            'unit' => 'm3',
            'unit_price' => 620.00,
            'line_total' => Quote::lineTotal(50, 620.00),
        ]);

        $quote->load('items')->recalculateTotals()->save();

        $conversation = app(MessagingService::class)->start($buyer, $company);
        $commerce = app(ChatCommerceService::class);
        $commerce->issueQuotation($conversation, $quote->refresh(), $staff);
        $commerce->acceptQuotation($conversation, $quote->refresh(), $buyer);

        $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

        return [$conversation->refresh(), $buyer, $company, $staff, $order->refresh()];
    }
}

if (! function_exists('olAdvanceTo')) {
    /** Drive the order to a given status through the supplier-side service. */
    function olAdvanceTo(Conversation $c, Order $order, User $staff, OrderStatus $target): Order
    {
        $path = [
            [OrderStatus::Confirmed, fn () => olLifecycle()->confirm($c, $order->refresh(), $staff)],
            [OrderStatus::InProduction, fn () => olLifecycle()->startProduction($c, $order->refresh(), $staff)],
            [OrderStatus::Shipped, fn () => olLifecycle()->ship($c, $order->refresh(), $staff)],
            [OrderStatus::Delivered, fn () => olLifecycle()->deliver($c, $order->refresh(), $staff)],
        ];

        foreach ($path as [$status, $move]) {
            $move();

            if ($status === $target) {
                break;
            }
        }

        return $order->refresh();
    }
}

if (! function_exists('olCard')) {
    function olCard(Conversation $c, MessageType $type): Message
    {
        return $c->messages()->where('type', $type->value)->latest('id')->firstOrFail();
    }
}
