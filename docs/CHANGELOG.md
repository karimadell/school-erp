# Changelog

> Record of major features/fixes as they land on `recovery/full-work-2026-07-31`. Newest entries first. Each entry names the PR/commit, what changed, what was verified, and any known non-blocking follow-up — never silently omitted.

---

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
