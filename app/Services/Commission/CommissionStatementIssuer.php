<?php

namespace App\Services\Commission;

use App\Enums\CommissionStatementStatus;
use App\Models\CommissionStatement;
use App\Models\CommissionStatementLine;
use App\Models\Company;
use App\Models\Order;
use App\Notifications\CommissionStatementIssuedNotification;
use App\Support\InvoiceNumberGenerator;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Issues the monthly marketplace-commission statements (owner decision
 * 2026-10-01: commission is collected from suppliers by manual MoMo / bank
 * deposit; there is no automatic debit). Run by `commission:issue-statements`
 * on the 1st of every month for the previous month.
 *
 * For a period (a calendar month, app timezone) it creates ONE statement per
 * supplier company (`orders.company_id`) per order currency, containing:
 *
 *  - a `charge` line for every order whose commission was charged
 *    (`CommissionCalculator::charge()`, `orders.commission_charged_at`) on or
 *    before the period end and that is not yet billed on a non-void
 *    statement, for its NET commission (charged − credited so far). In
 *    normal monthly running that is exactly "the orders charged in that
 *    month"; an order missed earlier (e.g. its statement was voided) is
 *    caught up on the next run rather than lost.
 *  - an `adjustment` line for every already-billed order whose net
 *    commission has since dropped (a credit after a refund, dispute or
 *    cancellation): the negative difference, so it is credited on the next
 *    statement instead of re-writing the old one.
 *
 * Money never crosses currencies. A company/currency whose total would be
 * zero or negative gets no statement — its adjustments are recomputed (and
 * so carried forward) on the next run. Idempotent: an existing non-void
 * statement for the same company + period + currency is never duplicated
 * (also enforced by a partial unique index); voiding a statement releases its
 * orders and lets the period be re-issued.
 */
class CommissionStatementIssuer
{
    public function __construct(private readonly InvoiceNumberGenerator $numbers) {}

    /**
     * Issue every statement due for the month containing $month.
     *
     * @return array{period: string, issued: list<CommissionStatement>, existing: int, skipped_non_positive: int}
     */
    public function issueForMonth(DateTimeInterface $month): array
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();
        $end = $start->endOfMonth();

        $issued = [];
        $existing = 0;
        $skipped = 0;

        foreach ($this->candidatePairs($end) as [$companyId, $currency]) {
            $result = $this->issueFor((int) $companyId, (string) $currency, $start, $end);

            match (true) {
                $result instanceof CommissionStatement => $issued[] = $result,
                $result === 'existing' => $existing++,
                default => $skipped++,
            };
        }

        return ['period' => $start->format('Y-m'), 'issued' => $issued, 'existing' => $existing, 'skipped_non_positive' => $skipped];
    }

    /**
     * The (company_id, currency) pairs with anything to bill up to $end.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function candidatePairs(CarbonImmutable $end): array
    {
        return $this->unbilledOrders($end)
            ->reorder()
            ->select(['orders.company_id', 'orders.currency'])
            ->distinct()
            ->toBase()
            ->get()
            ->map(fn (object $row) => [(int) $row->company_id, trim((string) $row->currency)])
            ->all();
    }

    /**
     * Charged orders (up to $end) whose net commission differs from what
     * non-void statement lines have billed so far, carrying `billed_sum` and
     * `charge_lines` (count of non-void charge lines) as extra columns.
     */
    private function unbilledOrders(CarbonImmutable $end): Builder
    {
        $billed = DB::table('commission_statement_lines')
            ->whereNull('voided_at')
            ->selectRaw("order_id, SUM(amount) AS billed_sum, SUM(CASE WHEN kind = 'charge' THEN 1 ELSE 0 END) AS charge_lines")
            ->groupBy('order_id');

        return Order::query()
            ->where('orders.is_commission_charged', true)
            ->whereNotNull('orders.commission_charged_at')
            ->where('orders.commission_charged_at', '<=', $end)
            ->leftJoinSub($billed, 'billed', fn ($join) => $join->on('billed.order_id', '=', 'orders.id'))
            ->whereRaw('(COALESCE(orders.commission_amount, 0) - orders.commission_credited_amount - COALESCE(billed.billed_sum, 0)) <> 0')
            ->select('orders.*')
            ->selectRaw('COALESCE(billed.billed_sum, 0) AS billed_sum, COALESCE(billed.charge_lines, 0) AS charge_lines')
            ->orderBy('orders.commission_charged_at')
            ->orderBy('orders.id');
    }

    /**
     * Issue the statement for one company + currency + period.
     *
     * @return CommissionStatement|'existing'|'non_positive'
     */
    public function issueFor(int $companyId, string $currency, CarbonImmutable $start, CarbonImmutable $end): CommissionStatement|string
    {
        try {
            $statement = DB::transaction(function () use ($companyId, $currency, $start, $end) {
                // Serialise concurrent runs for the same company.
                $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();

                $exists = CommissionStatement::query()
                    ->where('company_id', $companyId)
                    ->whereDate('period_start', $start->toDateString())
                    ->where('currency', $currency)
                    ->where('status', '!=', CommissionStatementStatus::Void->value)
                    ->exists();

                if ($exists) {
                    return 'existing';
                }

                $lines = $this->buildLines($companyId, $currency, $end);

                $charges = $lines->where('kind', CommissionStatementLine::KIND_CHARGE)
                    ->reduce(fn (string $c, array $l) => bcadd($c, $l['amount'], 2), '0.00');
                $adjustments = $lines->where('kind', CommissionStatementLine::KIND_ADJUSTMENT)
                    ->reduce(fn (string $c, array $l) => bcadd($c, $l['amount'], 2), '0.00');
                $total = bcadd($charges, $adjustments, 2);

                // Zero-amount statements are not issued; a net credit is
                // carried forward (recomputed next run).
                if (bccomp($total, '0', 2) <= 0) {
                    return 'non_positive';
                }

                $issuedAt = now();

                $statement = CommissionStatement::create([
                    'statement_number' => $this->numbers->commissionStatement(),
                    'company_id' => $company->getKey(),
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'currency' => $currency,
                    'status' => CommissionStatementStatus::Issued,
                    'charges_amount' => $charges,
                    'adjustments_amount' => $adjustments,
                    'total_amount' => $total,
                    'amount_paid' => '0.00',
                    'issued_at' => $issuedAt,
                    'due_date' => $issuedAt->copy()->startOfDay()->addDays(max(0, (int) config('timber.commission.statement_due_days', 15)))->toDateString(),
                ]);

                foreach ($lines as $line) {
                    $statement->lines()->create($line);
                }

                activity('commission_statement')
                    ->performedOn($statement)
                    ->event('issued')
                    ->withProperties([
                        'statement_number' => $statement->statement_number,
                        'company_id' => $companyId,
                        'period' => $start->format('Y-m'),
                        'currency' => $currency,
                        'total_amount' => $total,
                        'lines' => $lines->count(),
                    ])
                    ->log("Commission statement {$statement->statement_number} issued");

                $company->loadMissing('users');

                if ($company->users->isNotEmpty()) {
                    Notification::send($company->users, (new CommissionStatementIssuedNotification($statement))->afterCommit());
                }

                return $statement;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent run won the race for this period / these orders.
            return 'existing';
        }

        return $statement;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function buildLines(int $companyId, string $currency, CarbonImmutable $end): Collection
    {
        return $this->unbilledOrders($end)
            ->where('orders.company_id', $companyId)
            ->where('orders.currency', $currency)
            ->get()
            ->map(function (Order $order): ?array {
                $charged = bcadd((string) ($order->commission_amount ?? '0'), '0', 2);
                $credited = bcadd((string) ($order->commission_credited_amount ?? '0'), '0', 2);
                $net = bcsub($charged, $credited, 2);
                $due = bcsub($net, bcadd((string) $order->getAttribute('billed_sum'), '0', 2), 2);
                $isCharge = (int) $order->getAttribute('charge_lines') === 0;

                // An unbilled order fully credited before billing (e.g.
                // cancelled in the same month) has nothing to bill.
                if ($isCharge && bccomp($due, '0', 2) <= 0) {
                    return null;
                }

                return [
                    'order_id' => $order->getKey(),
                    'kind' => $isCharge ? CommissionStatementLine::KIND_CHARGE : CommissionStatementLine::KIND_ADJUSTMENT,
                    'order_reference' => (string) $order->reference_code,
                    'charged_at' => $order->commission_charged_at,
                    'order_subtotal' => bcadd((string) $order->subtotal_amount, '0', 2),
                    'commission_rate' => $order->commission_rate,
                    'commission_amount' => $charged,
                    'credited_amount' => $credited,
                    'amount' => $due,
                    'description' => $isCharge
                        ? "Commission on order {$order->reference_code}"
                        : "Credit on order {$order->reference_code} after billing",
                ];
            })
            ->filter()
            ->values();
    }
}
