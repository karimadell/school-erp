<?php

namespace App\Console\Commands;

use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use Illuminate\Console\Command;

/**
 * EnrollmentMode Inventory — read-only, no writes of any kind.
 *
 * Built ahead of the Phase 2 EnrollmentMode master-data change (introducing
 * family/external/no_enrollment alongside the existing full_time) so any
 * environment — local, UAT, or production — can be inspected first for
 * rows that don't cleanly match the four canonical codes before any seeder
 * runs there.
 *
 * code is the stable identity throughout this report. A matching
 * translated name is never treated as identity — it is reported only as a
 * diagnostic signal (e.g. "expected name exists under another code"),
 * never used to merge or identify rows. An unknown/non-canonical mode is
 * never classified as safe to delete, and is explicitly labelled as
 * historically referenced when Enrollment rows point at it.
 */
class EnrollmentModeInventory extends Command
{
    protected $signature = 'enrollment-modes:inventory';

    protected $description = 'Read-only EnrollmentMode inventory and canonical-code diagnostics. No writes of any kind.';

    /**
     * @var array<string, string>
     */
    private const CANONICAL_NAMES = [
        'full_time' => 'Очная форма обучения',
        'family' => 'Семейная форма обучения',
        'external' => 'Экстернат',
        'no_enrollment' => 'Без зачисления',
    ];

    public function handle(): int
    {
        $this->components->info('EnrollmentMode Inventory (read-only, no writes performed)');

        $modes = EnrollmentMode::query()
            ->withCount('enrollments')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $this->reportModes($modes);
        $this->reportReferencesByYear();
        $this->reportEnrollmentTotals();
        $this->reportCanonicalDiagnostics($modes);

        $this->newLine();
        $this->components->info('Inventory complete. No data was created, updated, or deleted.');

        return self::SUCCESS;
    }

    private function reportModes($modes): void
    {
        $this->header('EnrollmentMode rows');

        if ($modes->isEmpty()) {
            $this->warn('No EnrollmentMode rows exist.');

            return;
        }

        $this->table(
            ['id', 'code', 'name_ru', 'short_name_ru', 'name_en', 'name_ar', 'is_active', 'display_order', 'enrollments'],
            $modes->map(fn (EnrollmentMode $mode) => [
                $mode->id,
                $mode->code,
                $mode->name_ru,
                $mode->short_name_ru ?? '(нет)',
                $mode->name_en ?? '(нет)',
                $mode->name_ar ?? '(нет)',
                $mode->is_active ? 'yes' : 'no',
                $mode->display_order,
                $mode->enrollments_count,
            ]),
        );
    }

    private function reportReferencesByYear(): void
    {
        $this->header('Enrollment references by mode + academic year');

        $rows = Enrollment::query()
            ->selectRaw('enrollment_mode_id, academic_year_id, COUNT(*) AS total')
            ->groupBy('enrollment_mode_id', 'academic_year_id')
            ->with(['enrollmentMode:id,code', 'academicYear:id,name'])
            ->orderBy('academic_year_id')
            ->orderBy('enrollment_mode_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No Enrollment rows exist.');

            return;
        }

        $this->table(
            ['enrollment_mode_id', 'code', 'academic_year_id', 'academic_year', 'count'],
            $rows->map(fn (Enrollment $row) => [
                $row->enrollment_mode_id ?? '(NULL)',
                $row->enrollmentMode?->code ?? '(NULL)',
                $row->academic_year_id ?? '(NULL)',
                $row->academicYear?->name ?? '(нет данных)',
                $row->total,
            ]),
        );
    }

    private function reportEnrollmentTotals(): void
    {
        $this->header('Enrollment totals');

        $total = Enrollment::query()->count();
        $nullMode = Enrollment::query()->whereNull('enrollment_mode_id')->count();

        $this->components->twoColumnDetail('Total Enrollment rows', (string) $total);
        $this->components->twoColumnDetail('Enrollment rows with enrollment_mode_id IS NULL', (string) $nullMode);
    }

    private function reportCanonicalDiagnostics($modes): void
    {
        $this->header('Canonical mode diagnostics (code is the stable identity — a name match is never identity)');

        $byCode = $modes->keyBy('code');
        $flags = [];

        foreach (self::CANONICAL_NAMES as $code => $expectedName) {
            $mode = $byCode->get($code);

            if (! $mode) {
                $flags[] = "MISSING — canonical code \"{$code}\" does not exist yet.";

                continue;
            }

            $flags[] = "OK — canonical code \"{$code}\" exists (id={$mode->id}).";

            if ($mode->name_ru !== $expectedName) {
                $flags[] = "NAME MISMATCH — code \"{$code}\" (id={$mode->id}) has name_ru \"{$mode->name_ru}\", expected \"{$expectedName}\".";
            }
        }

        foreach (self::CANONICAL_NAMES as $code => $expectedName) {
            $holders = $modes->where('name_ru', $expectedName)->where('code', '!==', $code);

            foreach ($holders as $holder) {
                $flags[] = "NAME UNDER ANOTHER CODE — expected name \"{$expectedName}\" (for \"{$code}\") is currently on code \"{$holder->code}\" (id={$holder->id}).";
            }
        }

        $unknown = $modes->whereNotIn('code', array_keys(self::CANONICAL_NAMES));
        foreach ($unknown as $mode) {
            $referenced = $mode->enrollments_count > 0;
            $flags[] = sprintf(
                'UNKNOWN/NON-CANONICAL — code "%s" (id=%d, name_ru="%s") is not one of the four canonical codes.%s',
                $mode->code,
                $mode->id,
                $mode->name_ru,
                $referenced
                    ? " HISTORICALLY REFERENCED by {$mode->enrollments_count} Enrollment row(s) — must not be deleted or repurposed without review."
                    : ' Not currently referenced by any Enrollment.',
            );
        }

        $duplicateNames = $modes->groupBy('name_ru')->filter(fn ($group) => $group->count() > 1);
        foreach ($duplicateNames as $name => $group) {
            $codes = $group->pluck('code')->implode('", "');
            $flags[] = "DUPLICATE NAME — name_ru \"{$name}\" is shared by codes \"{$codes}\".";
        }

        $nullReferenceCount = Enrollment::query()->whereNull('enrollment_mode_id')->count();
        if ($nullReferenceCount > 0) {
            $flags[] = "NULL REFERENCES — {$nullReferenceCount} Enrollment row(s) have enrollment_mode_id IS NULL.";
        }

        if ($flags === []) {
            $this->info('No diagnostics to report.');

            return;
        }

        foreach ($flags as $flag) {
            $this->line('- '.$flag);
        }
    }

    private function header(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail("<fg=yellow;options=bold>{$title}</>", '');
    }
}
