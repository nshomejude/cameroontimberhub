<?php

use App\Enums\CompanyReviewStatus;
use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Livewire\Messaging\Thread;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyReviewService;
use App\Services\OrderLifecycleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */

function crReviews(): CompanyReviewService
{
    return app(CompanyReviewService::class);
}

/**
 * olScene() (from tests/Support/order_lifecycle_helpers.php), driven all the
 * way to a completed order — the only state in which a review is possible.
 * Sharing the helper keeps the two suites describing the same world rather
 * than two subtly different ones.
 *
 * @return array{0: Conversation, 1: User, 2: Company, 3: User, 4: Order}
 */
function crCompletedScene(): array
{
    [$c, $buyer, $company, $staff, $order] = olScene();

    olAdvanceTo($c, $order, $staff, OrderStatus::Delivered);
    app(OrderLifecycleService::class)->complete($c, $order->refresh(), $buyer);

    return [$c, $buyer, $company, $staff, $order->refresh()];
}

/* ============================================================== ELIGIBILITY */

it('lets the buyer review only after the order is completed', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    // Awarded — not reviewable.
    expect(crReviews()->canReview($buyer, $order))->toBeFalse();
    expect(fn () => crReviews()->create($order, $buyer, ['rating' => 5]))
        ->toThrow(RuntimeException::class, 'once the order is completed');

    olAdvanceTo($c, $order, $staff, OrderStatus::Delivered);

    // Delivered is still not completed — the buyer has not confirmed receipt.
    expect(crReviews()->canReview($buyer, $order->refresh()))->toBeFalse();
    expect(fn () => crReviews()->create($order->refresh(), $buyer, ['rating' => 5]))
        ->toThrow(RuntimeException::class, 'once the order is completed');

    app(OrderLifecycleService::class)->complete($c, $order->refresh(), $buyer);

    expect(crReviews()->canReview($buyer, $order->refresh()))->toBeTrue();
    expect(CompanyReview::count())->toBe(0);
});

it('refuses a review from someone who does not own the order', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    $stranger = User::factory()->create(['email' => 'crstranger'.uniqid().'@example.com']);

    expect(crReviews()->canReview($stranger, $order))->toBeFalse();
    expect(fn () => crReviews()->create($order, $stranger, ['rating' => 5]))
        ->toThrow(HttpException::class, 'Only the buyer on an order may review the supplier.');

    // The supplier's own staff cannot review themselves either.
    expect(crReviews()->canReview($staff, $order))->toBeFalse();
    expect(fn () => crReviews()->create($order, $staff, ['rating' => 5]))
        ->toThrow(HttpException::class);

    expect(CompanyReview::count())->toBe(0);
});

it('refuses a review posted through the thread by the supplier', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    expect(fn () => app(OrderLifecycleService::class)->review($c, $order, $staff, ['rating' => 5]))
        ->toThrow(HttpException::class, 'Only the buyer on this conversation can do that.');

    expect(CompanyReview::count())->toBe(0);
});

/* ========================================================== ONE PER ORDER */

it('allows one review per order and rejects the second in PHP', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    crReviews()->create($order, $buyer, ['rating' => 5, 'body' => 'Excellent.']);

    expect(fn () => crReviews()->create($order->refresh(), $buyer, ['rating' => 1]))
        ->toThrow(RuntimeException::class, 'already reviewed this order');

    expect(CompanyReview::where('order_id', $order->getKey())->count())->toBe(1);
});

it('enforces one review per order with a database constraint, not just PHP', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    crReviews()->create($order, $buyer, ['rating' => 5]);

    // Bypass the service entirely and go straight at the table. The UNIQUE
    // index on order_id is what actually holds the line, so two concurrent
    // submissions cannot both land.
    // Wrapped in a nested transaction so the deliberate constraint violation
    // rolls back to a SAVEPOINT instead of poisoning the test's own
    // transaction — Postgres aborts everything after an error otherwise.
    expect(fn () => DB::transaction(fn () => DB::table('company_reviews')->insert([
        'company_id' => $company->getKey(),
        'user_id' => $buyer->getKey(),
        'order_id' => $order->getKey(),
        'rating' => 1,
        'status' => 'published',
        'author_name' => 'Someone else',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(CompanyReview::where('order_id', $order->getKey())->count())->toBe(1);
});

it('rejects an out-of-range rating in PHP and in the database', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    expect(fn () => crReviews()->create($order, $buyer, ['rating' => 6]))
        ->toThrow(RuntimeException::class, 'between 1 and 5');
    expect(fn () => crReviews()->create($order, $buyer, ['rating' => 0]))
        ->toThrow(RuntimeException::class, 'between 1 and 5');

    // Wrapped in a nested transaction so the deliberate constraint violation
    // rolls back to a SAVEPOINT instead of poisoning the test's own
    // transaction — Postgres aborts everything after an error otherwise.
    expect(fn () => DB::transaction(fn () => DB::table('company_reviews')->insert([
        'company_id' => $company->getKey(),
        'user_id' => $buyer->getKey(),
        'order_id' => $order->getKey(),
        'rating' => 9,
        'status' => 'published',
        'author_name' => 'Cheater',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

/* ========================================================== AGGREGATES */

it('recomputes rating_avg and rating_count from real rows only', function () {
    [$c1, $buyer1, $company, $staff, $order1] = crCompletedScene();

    // Before any review the supplier has no rating at all — not "0.0 / 5".
    $company->refresh();
    expect((int) $company->rating_count)->toBe(0)
        ->and($company->rating_avg)->toBeNull()
        ->and($company->hasRating())->toBeFalse();

    crReviews()->create($order1, $buyer1, ['rating' => 5]);

    $company->refresh();
    expect($company->rating_count)->toBe(1)
        ->and((float) $company->rating_avg)->toBe(5.0)
        ->and($company->hasRating())->toBeTrue();

    // A second completed order with the SAME company, from a second buyer.
    [$c2, $buyer2, $company2, $staff2, $order2] = crCompletedScene();
    $order2->forceFill(['company_id' => $company->getKey()])->save();

    crReviews()->create($order2->refresh(), $buyer2, ['rating' => 2]);

    $company->refresh();
    expect($company->rating_count)->toBe(2)
        // (5 + 2) / 2 = 3.5
        ->and((float) $company->rating_avg)->toBe(3.5);
});

it('drops a moderated-out review from the aggregates', function () {
    [$c1, $buyer1, $company, $staff, $order1] = crCompletedScene();

    $review = crReviews()->create($order1, $buyer1, ['rating' => 5]);

    $company->refresh();
    expect($company->rating_count)->toBe(1);

    crReviews()->setStatus($review, CompanyReviewStatus::Rejected);

    $company->refresh();
    // Back to no rating, so the star strip disappears rather than showing 0.
    expect((int) $company->rating_count)->toBe(0)
        ->and($company->rating_avg)->toBeNull()
        ->and($company->hasRating())->toBeFalse();
});

/* =============================================================== ESCAPING */

it('escapes a review body everywhere it is rendered', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';

    app(OrderLifecycleService::class)->review($c, $order, $buyer, [
        'rating' => 4,
        'title' => 'Bad <b>title</b>',
        'body' => $payload,
    ]);

    // Stored raw — escaping is a rendering concern, not a storage one.
    $review = CompanyReview::where('order_id', $order->getKey())->firstOrFail();
    expect($review->body)->toBe($payload);

    // In the thread. The test is that the dangerous *markup* never appears —
    // the escaped text legitimately still contains the substring "onerror=",
    // which is exactly the point: it is inert text, not an attribute.
    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    expect($html)
        ->not->toContain('<script>alert')
        ->not->toContain('<img src=x')
        ->not->toContain('<b>title</b>')
        // Proof it was escaped rather than merely stripped.
        ->and($html)->toContain('&lt;script&gt;')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->toContain('Bad &lt;b&gt;title&lt;/b&gt;');

    // And on the public supplier profile, where a stranger reads it.
    $profile = $this->get(route('companies.show', $company->slug));
    $profile->assertOk();
    expect($profile->getContent())
        ->not->toContain('<script>alert')
        ->not->toContain('<img src=x')
        ->and($profile->getContent())->toContain('&lt;script&gt;');
});

/* =========================================================== THREAD CARD */

it('posts the review as a card and links it to the review row', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    $review = app(OrderLifecycleService::class)->review($c, $order, $buyer, [
        'rating' => 5,
        'title' => 'Smooth shipment',
        'body' => 'Delivered on time.',
    ]);

    $card = $c->messages()->where('type', MessageType::CompanyReview->value)->firstOrFail();

    expect($review->message_id)->toBe($card->getKey())
        ->and($card->related_id)->toBe($review->getKey())
        ->and($card->payloadValue('rating'))->toBe(5);

    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    expect($html)->toContain('Smooth shipment')->toContain('5 out of 5');
});

it('hides a moderated-out review card from the thread', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    $review = app(OrderLifecycleService::class)->review($c, $order, $buyer, [
        'rating' => 5,
        'body' => 'Removed later.',
    ]);

    crReviews()->setStatus($review, CompanyReviewStatus::Rejected);

    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    expect($html)->not->toContain('Removed later.');
});

/* ================================================================= ROUTES */

it('accepts a review over the plain route and refuses a repeat', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    $this->actingAs($buyer)
        ->post(route('chat.order.review', [$c, $order]), ['rating' => 4, 'body' => 'Good.'])
        ->assertRedirect();

    expect(CompanyReview::where('order_id', $order->getKey())->count())->toBe(1);

    $this->actingAs($buyer)
        ->post(route('chat.order.review', [$c, $order]), ['rating' => 1])
        ->assertSessionHas('error');

    expect(CompanyReview::where('order_id', $order->getKey())->count())->toBe(1);
});

it('404s a non-participant posting a review and leaks nothing', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    $stranger = User::factory()->create(['email' => 'crroutestranger'.uniqid().'@example.com']);

    $response = $this->actingAs($stranger)
        ->post(route('chat.order.review', [$c, $order]), ['rating' => 5]);

    $response->assertNotFound();
    expect($response->getContent())
        ->not->toContain($order->reference_code)
        ->not->toContain($company->name);

    expect(CompanyReview::count())->toBe(0);
});

it('rate limits review submissions', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    // Driven through the route itself rather than by pre-seeding the limiter:
    // ThrottleRequests hashes its own key, so a hand-written RateLimiter::hit()
    // would not be the same bucket and the test would prove nothing.
    $url = route('chat.order.review', [$c, $order]);

    // The first lands; the next four are refused as duplicates by the
    // one-per-order rule but still consume budget.
    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($buyer)->post($url, ['rating' => 5]);
    }

    $this->actingAs($buyer)->post($url, ['rating' => 5])->assertStatus(429);

    // Exactly one review exists regardless of how many attempts were made.
    expect(CompanyReview::where('order_id', $order->getKey())->count())->toBe(1);
});

/* ================================================================ PROFILE */

it('shows no review section on a supplier profile with no reviews', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    // No empty shell, no "be the first to review", no zero-star strip.
    expect($response->getContent())->not->toContain('Buyer reviews');
});

it('shows real reviews on the supplier profile once they exist', function () {
    [$c, $buyer, $company, $staff, $order] = crCompletedScene();

    crReviews()->create($order, $buyer, [
        'rating' => 5,
        'title' => 'Excellent Sapele',
        'body' => 'Exactly as specified.',
    ]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk()
        ->assertSee('Buyer reviews')
        ->assertSee('Excellent Sapele')
        ->assertSee('Exactly as specified.')
        ->assertSee('verified order');
});
