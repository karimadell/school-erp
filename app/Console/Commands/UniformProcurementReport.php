<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Console\Command;

/**
 * Uniform Procurement Report — read-only, no writes of any kind.
 *
 * Answers exactly what a factory procurement order needs: for every sold
 * uniform (Item, EXACT size), how many were sold, aggregated over an
 * academic year and/or an explicit date range.
 *
 * Sourced entirely from InvoiceItem.metadata['item']/['size'] — the exact
 * values the operator actually selected in Quick Registration at the time
 * of sale (QuickStudentRegistrationService::metadata(), CATEGORY_UNIFORM
 * branch), never re-derived from the currently-configured FeePrice/
 * uniform_products catalog. This is deliberate: a catalog row can change
 * or be retired after the sale: the report must reflect what was actually
 * sold, not what is currently configured.
 *
 * A legacy grouped-range value (e.g. '6–10') sold before this corrective
 * pass existed is reported exactly as sold — under its own group key,
 * never silently reinterpreted as an individual size. This report is a
 * read of historical fact, not a data-cleanup tool.
 *
 * Cancelled invoices are excluded (Invoice::STATUS_CANCELLED) — this is
 * the only cancellation granularity that exists in this codebase today
 * (no per-line-item cancelled/refunded status exists on invoice_items
 * itself). A partially-credited-but-not-cancelled line is still counted:
 * a student credit reduces what is owed, not the physical fact that a
 * garment in that exact size was already sold and must be procured.
 */
class UniformProcurementReport extends Command
{
    protected $signature = 'finance:uniform-procurement-report
        {--year= : Exact or partial academic year name to target, e.g. "2026/2027"}
        {--from= : Only invoices created on/after this date (Y-m-d)}
        {--to= : Only invoices created on/before this date (Y-m-d)}';

    protected $description = 'Read-only Uniform Procurement Report — Item + exact size + quantity, grouped for factory ordering. No writes of any kind.';

    public function handle(): int
    {
        $this->components->info('Uniform Procurement Report (read-only, no writes performed)');

        $year = $this->resolveYear();

        $uniformFeeIds = Fee::where('category', Fee::CATEGORY_UNIFORM)->pluck('id');

        $query = InvoiceItem::query()
            ->whereIn('fee_id', $uniformFeeIds)
            ->whereHas('invoice', function ($invoiceQuery) use ($year) {
                $invoiceQuery->where('status', '!=', Invoice::STATUS_CANCELLED);
                if ($year) {
                    $invoiceQuery->where('academic_year_id', $year->id);
                }
                if ($from = $this->option('from')) {
                    $invoiceQuery->whereDate('created_at', '>=', $from);
                }
                if ($to = $this->option('to')) {
                    $invoiceQuery->whereDate('created_at', '<=', $to);
                }
            });

        $items = $query->get(['id', 'quantity', 'metadata']);

        if ($items->isEmpty()) {
            $this->warn('No uniform invoice lines match the given filters.');

            return self::SUCCESS;
        }

        $rows = $items->map(function (InvoiceItem $line) {
            $item = $line->metadata['item'] ?? null;
            $size = $line->metadata['size'] ?? null;

            return [
                // A line with no item/size in its metadata predates this
                // codebase's own metadata capture entirely (never silently
                // dropped or merged into another group) — reported as its
                // own explicit, visibly-incomplete bucket rather than
                // hidden or guessed at.
                'item' => $item ?? '(нет данных — старая запись)',
                'size' => $size ?? '(нет данных — старая запись)',
                'quantity' => (int) ($line->quantity ?? 1),
                'is_legacy_group' => $size && ! $this->isExactSize($size),
            ];
        });

        $grouped = $rows->groupBy(fn (array $row) => $row['item'].'|'.$row['size']);

        $this->header('Uniform procurement — Item + exact size + quantity');
        $this->table(
            ['item', 'size', 'quantity', 'legacy grouped size?'],
            $grouped->map(fn ($group) => [
                $group->first()['item'],
                $group->first()['size'],
                $group->sum('quantity'),
                $group->first()['is_legacy_group'] ? 'YES — not an exact size, procurement figure not directly usable for this row' : 'no',
            ])->sortBy(fn ($row) => $row[0].$row[1])->values(),
        );

        $legacyCount = $rows->where('is_legacy_group', true)->sum('quantity');
        if ($legacyCount > 0) {
            $this->warn("{$legacyCount} unit(s) sold under a legacy grouped size (e.g. '6–10') — these cannot be procured against an exact size and are reported separately above, never merged into an exact-size row.");
        }

        $this->newLine();
        $this->components->info('Report complete. No data was created, updated, or deleted.');

        return self::SUCCESS;
    }

    private function resolveYear(): ?AcademicYear
    {
        if (! $needle = $this->option('year')) {
            return null;
        }
        $normalized = preg_replace('/\s+/', '', $needle);
        $year = AcademicYear::all()->first(fn (AcademicYear $y) => preg_replace('/\s+/', '', $y->name) === $normalized);
        if (! $year) {
            $this->components->error("No academic year found matching \"{$needle}\" — showing all years instead.");
        }

        return $year;
    }

    /**
     * A grouped legacy size ('6–10', '12–16', 'от S') is textually
     * distinguishable from an exact size on sight — it always contains a
     * dash/range marker or the word "от" ("from"). No new schema/column
     * was introduced to classify old vs. new: the value itself already
     * carries that distinction.
     */
    private function isExactSize(string $size): bool
    {
        return ! str_contains($size, '–') && ! str_contains($size, '-') && ! str_contains(mb_strtolower($size), 'от');
    }

    private function header(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail("<fg=yellow;options=bold>{$title}</>", '');
    }
}
