<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;

/**
 * The visitor's session-backed RFQ shortlist — what "Add to RFQ List" on the
 * product detail page writes to, and what the RFQ form reads back.
 *
 * Deliberately session-only: the RFQ intake is open to anonymous buyers, so
 * there is no account to hang a persisted list off. The list survives for the
 * session and is consumed (and cleared) when a quote request is submitted.
 */
class RfqList
{
    public const KEY = 'rfq_list';

    /** @return list<int> product ids, insertion-ordered */
    public function ids(): array
    {
        return array_values(array_unique(array_map('intval', (array) Session::get(self::KEY, []))));
    }

    public function count(): int
    {
        return count($this->ids());
    }

    public function has(Product $product): bool
    {
        return in_array((int) $product->getKey(), $this->ids(), true);
    }

    /** Adds the product, or removes it when it is already listed. Returns the new state. */
    public function toggle(Product $product): bool
    {
        $ids = $this->ids();
        $id = (int) $product->getKey();

        if (in_array($id, $ids, true)) {
            $this->put(array_values(array_diff($ids, [$id])));

            return false;
        }

        // Bounded so a scripted session cannot grow the cookie/session payload.
        $this->put(array_slice([...$ids, $id], -25));

        return true;
    }

    public function remove(Product $product): void
    {
        $this->put(array_values(array_diff($this->ids(), [(int) $product->getKey()])));
    }

    public function clear(): void
    {
        Session::forget(self::KEY);
    }

    /**
     * The listed products, still subject to the public visibility gate — a
     * product that was archived (or whose supplier was unpublished) after being
     * shortlisted silently drops out.
     *
     * @return Collection<int, Product>
     */
    public function products(): Collection
    {
        $ids = $this->ids();

        if ($ids === []) {
            return Product::query()->whereRaw('1 = 0')->get();
        }

        return Product::query()
            ->active()
            ->whereKey($ids)
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->with(['company:id,slug,legal_name,trade_name', 'species:id,slug,common_name'])
            ->get()
            ->sortBy(fn (Product $p) => array_search((int) $p->getKey(), $ids, true))
            ->values();
    }

    /** @param list<int> $ids */
    private function put(array $ids): void
    {
        Session::put(self::KEY, array_values(array_unique($ids)));
    }
}
