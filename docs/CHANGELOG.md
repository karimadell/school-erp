# Changelog

> Record of major features/fixes as they land on `recovery/full-work-2026-07-31`. Newest entries first. Each entry names the PR/commit, what changed, what was verified, and any known non-blocking follow-up — never silently omitted.

---

## 2026-09-22 — Finance: Student Stolovaya daily-meal window (Phase 1)

**PR:** [#85 — feat(finance): add Student Stolovaya daily-meal window (Phase 1)](https://github.com/karimadell/school-erp/pull/85)
**Merge commit:** `eee4d7875a3e3f07d7fa2e3221bf87240539d9ea`
**Feature commit:** `de5cabefffec2f9660163a0efca6569dc390e7fe`
**Corrective commit (same PR, owner-approved P1):** `6fd0cd29e4d88be3b3942250aa0b9e6dd9349683`

- Adds a dedicated **Столовая** card on the Приход (income) landing page, alongside the existing **Буфет** card, opening a thin per-student daily-meal screen: student search (reuses the existing student-search screen — no new search UI), date (defaults to today), a canonical Food `MealPlan` picker limited to plans that actually have a daily `FeePrice` (`MealPlan::sellableFood()`, extracted so Charge & Collect and Stolovaya share one query instead of duplicating it), quantity fixed at 1, and paid-now/debt settlement.
- **No new pricing or accounting engine.** `StolovayaController` never resolves a price, issues an invoice/coverage, or posts a payment itself — `StoreStolovayaChargeRequest` (a narrow subclass of the existing Charge & Collect request) server-resolves `fee_id`, forces `food_duration_mode = day` and `quantity = 1`, and the request is handed to the same `ChargeAndCollectService::chargeAndCollect()` that `FinanceOperationsController::chargeStore()` already uses. `FeePrice` remains billing-authoritative; existing idempotency protection is reused unchanged; no `RevenueEntry`/`RevenueCategory` is ever touched by this flow (Столовая income is Student Food accounting — Invoice/Payment/CashTransaction — not a Revenue-flow entry, unlike the existing Буфет/donation/other income cards).
- Quantity is deliberately fixed at 1 for this phase: Food billing has no quantity concept independent of billable-day-count, so no client-facing multiplier was invented in the controller.
- Employee/staff (Сотрудник) Stolovaya, salary-deduction settlement, and any `RevenueCategory` change are explicitly **out of scope** for this phase.
- New Russian-only translation keys added under `lang/ru/finance_workspace.php` (`stolovaya_*`, `income_type_stolovaya*`) — no English/Arabic keys yet, consistent with the project's RU-first localization phasing.
- Gated by the same `manage invoices` permission `chargeCreate()`/`chargeStore()` already require — Столовая is not a separately-permissioned surface.
- **Owner-approved P1 corrective (same PR, commit `6fd0cd2`):** review of this PR found the pre-existing Food overlap guard (`guardAgainstOverlappingFoodCoverage()`) scoped duplicate coverage by student+fee+date only. Since every Food `MealPlan` shares one Fee, that treated two different, legitimate same-day meals (e.g. Обед + Напиток) as a conflicting duplicate. The guard is now scoped by student+fee+**MealPlan**(`option_value`)+date, reading the MealPlan identity `ServiceCoverageService` already stamps onto every Food coverage row from the resolved `FeePrice` (the Phase 4B canonical identity) — no migration needed. The same MealPlan twice on the same date is still rejected, and the rejection message now names the specific conflicting meal. This guard is shared by every Food entry point (Charge & Collect, Quick Registration, Unified Collection, and the new Stolovaya screen); non-Food overlap/duplicate-invoice behavior is untouched.
- Tests (independently re-run on `recovery/full-work-2026-07-31` HEAD during documentation):
  - `StolovayaStudentPhase1Test` — 16 passed (66 assertions): card visibility, page access, meal-plan filtering, FeePrice-not-MealPlan.price pricing, single-day date handling, paid-now Invoice/Payment/CashTransaction correctness, unpaid/debt correctness, idempotent resubmission, same-day-different-meal-plans allowed, same-meal-plan-different-date allowed, overlap-guard rejection (same meal, same date), Buffet unaffected, `school_food` category never referenced, unauthorized-role exclusion from both the create form and the submit endpoint.
  - Food/Buffet regression (`FinanceFoodBuffetCategorySeparationTest`, `ChargeAndCollectFoodTest`, `ChargeAndCollectTest`, `FoodDailyBillingTest`) — 81 passed (384 assertions), no regressions.
  - Full Finance suite — 1888 passed, 14 skipped, 2 known pre-existing unrelated baseline failures (`EnumMigrationPortabilityTest`, `TuitionPaymentPeriodAmbiguityGuardTest`), untouched by this change.
- **Known non-blocking follow-up (not done by this change):** manual browser UAT of the Столовая card → student flow has not yet been performed.

## 2026-09-21 — Finance: 2026/2027 Food price & payment_period correction (UAT verified, closed)

**Food Phase 4B merge (identity migration):** `baf859e75d38549e5edba874a6550fdffc65af6c`
**Food price/payment_period groundwork merge:** `b74a967117d4cfb2f231d4b6affd1a3bc49622f7`
**Food-only corrective feature commit:** `510b1bf467ac2480b867d15d38af873086283634`
**Food-only corrective merge (authoritative):** `daf945a2e59c5bef749f852121aa9f82407b8cb0`

- **Phase 4B (identity only, no price change):** the three legacy a-la-carte Food items whose `fee_prices.option_value` still held literal Russian text — Суп, Второе блюдо, Напиток — were migrated to real `MealPlan` identities, joining the three already-migrated items. This step changed **only** `option_value` (legacy text → numeric MealPlan id); it did **not** change any Food amount or `payment_period`. Canonical Food catalog is now:
  - #1 Комплексное питание
  - #2 Завтрак
  - #3 Обед
  - #4 Суп
  - #5 Второе блюдо
  - #6 Напиток
- **Food-only price/payment_period corrective:** a dedicated, guarded command — `php artisan finance:correct-food-2026-2027 --year-id=<id>` — applies the owner-approved 2026/2027 Food prices and normalizes `payment_period` to `daily` for all six canonical items. Default is dry-run; `--apply` is required to write. This path is intentionally isolated from Registration, Tuition, Transport, Uniform, and After-School — it cannot invoke, be blocked by, or accidentally mutate any of them.
- Final approved 2026/2027 Food prices (`payment_period = daily` for all six):
  - Комплексное питание — 250 EGP
  - Завтрак — 100 EGP
  - Обед — 150 EGP
  - Суп — 50 EGP
  - Второе блюдо — 100 EGP
  - Напиток — 10 EGP
- **UAT (2026-09-21):** Phase 4B `--apply` completed successfully; the Food-only corrective's dry-run matched the intended six-row plan exactly; the owner-approved `--apply` completed successfully; independent post-apply verification confirmed all six FeePrice/MealPlan values matched the approved targets exactly, `option_value` identities were unchanged throughout, and no FeePrice/MealPlan row was created or deleted; a subsequent idempotency dry-run showed all six rows as `NO CHANGE`; no non-Food category was invoked at any point.
- **Final UAT state: Food 2026/2027 corrective CLOSED / VERIFIED / IDEMPOTENT.**
- Tests: `CorrectFood20262027PricesTest` — 18 passed; `AcademicYear20262027PriceCorrectiveTest` + `UatMasterDataRepairTest` + `FoodPhase4BLegacyMealPlanMigrationTest` — 63 passed; full Finance suite — 1870 passed, 14 skipped, 2 known pre-existing unrelated baseline failures (`EnumMigrationPortabilityTest`, `TuitionPaymentPeriodAmbiguityGuardTest`, untouched by this change).

**Safety note:** the legacy whole-year command `finance:correct-2026-2027-prices` must **not** currently be treated as the operational Food correction path on UAT. Read-only discovery found it has independent incompatibilities unrelated to Food — stale hardcoded Fee ids in Registration/Transport/Uniform, duplicate active Tuition tariff rows, and an unapproved After-School Fee-creation proposal. None of those are fixed by this change. The operational Food path is `finance:correct-food-2026-2027`.

**Known non-blocking follow-up (not done by this change):** browser-based UAT of the modern Food purchase flows (Quick Registration / Unified Collection) against the corrected prices has not yet been performed.

## 2026-09-15 — Finance: existing-student Food purchases via Charge & Collect

**PR:** [#52 — Finance: support Food purchases for existing students](https://github.com/karimadell/school-erp/pull/52)
**Merge commit:** `2781bc3bad58ec33427a6c2ef796ccd9168c2ca2`
**Feature commit:** `8a739b9f26189e4f5282b2db1153d7211ef2fe0c`

- Existing students can now purchase **new** Food coverage through the existing Finance → student → Charge & Collect workflow — without repeating Quick Registration.
- The **same** `Student` record is reused; no duplicate Student or Enrollment is created.
- Each new Food purchase creates a **new** `Invoice` and a **new** `ServiceCoverage`; historical Food invoices/coverage remain unchanged and immutable.
- Supported Food duration modes, using the existing authoritative Food machinery:
  - one day
  - school week
  - teaching-day based selection (where supported)
  - custom date range
- Food pricing/coverage resolution reuses the existing `FoodBillableDayCalculator`, `AcademicCalendar`, `InvoiceCalculationService`, and `InvoiceIssuanceService` — no second pricing engine, no client-side arithmetic. Non-teaching days/holidays continue to follow `AcademicCalendar` rules.
- Overlapping Food coverage for the same student/Food fee is rejected safely (friendly Russian validation message, no HTTP 500, no partially persisted Invoice/Payment/Coverage). Sequential, non-overlapping Food coverage for the same student is allowed.
- Partial/full immediate payment continues through the existing payment, allocation, cash, receipt, and debt architecture — unchanged.
- Charge & Collect's existing idempotency protection remains in place — a double submit does not create duplicate Food invoices/payments.
- No migration / schema change.
- Tests:
  - `ChargeAndCollectFoodTest` — 12 passed / 89 assertions
  - Full Finance suite — 1401 passed, 14 skipped, 1 known pre-existing unrelated `EnumMigrationPortabilityTest` baseline failure (untouched by this change)

**Known non-blocking follow-up (not fixed by this change):** a fully refunded Food invoice remains open/unpaid unless explicitly voided. Because its immutable `ServiceCoverage` still belongs to a non-cancelled invoice, repurchasing the exact same Food dates remains blocked until the original invoice is explicitly cancelled/voided. Tracked in `docs/06_Roadmap.md`.
