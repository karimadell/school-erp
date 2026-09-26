# Changelog

> Record of major features/fixes as they land on `recovery/full-work-2026-07-31`. Newest entries first. Each entry names the PR/commit, what changed, what was verified, and any known non-blocking follow-up — never silently omitted.

---

## 2026-09-26 — Finance: Employee cash Stolovaya (Phase 2) — Browser UAT PASSED, CLOSED

**PR:** [#86 — feat(finance): add Employee cash Stolovaya (Phase 2)](https://github.com/karimadell/school-erp/pull/86)
**Merge commit (standard merge, PR #86):** `50eb5a6ac66709e8ad0388f7573d1c10d8d7c3fb`
**Feature commit:** `f7f70696a78a396481c80539672c8a065aa83ac2`
**Corrective commit (same PR, owner-approved — revenue-integrity boundary):** `df4322b77d83e531cfe1c626680896c984692426`

- Adds a dedicated, owner-approved point-of-sale cash-sale workflow for **employee** daily meals — entirely separate from Student Stolovaya (Phase 1, closed) and from Buffet. Employee identity is `User` (`is_active` + at least one role) — the same eligibility rule `EmployeePayrollService::assertEmployee()` and the existing Filament payroll picker already established; no new identity model was invented.
- New `EmployeeStolovayaController` / `EmployeeFoodPurchaseService` / `StoreEmployeeFoodPurchaseRequest` / `StaffFoodPurchase` — a small dedicated structured snapshot (employee/meal/quantity/unit-price/food_date) that `RevenueEntry` alone cannot hold, one-to-one with the `RevenueEntry` it causes.
- Pricing is 100% `FeePrice`-authoritative via the existing `InvoiceCalculationService::resolveCoverageBasisPrice()` — never `MealPlan.price`, never a client-supplied amount. Quantity ≥ 1 (max 20), server-multiplied (`bcmul`).
- Accounting: `StaffFoodPurchase` → `RevenueEntry` (`category = school_food`) → `RevenueService` → exactly one `CashTransaction`. No Invoice, no ServiceCoverage, no employee debt. **Phase 2 is cash-only** — no card/bank/InstaPay. The owner cash account is excluded from the picker, and an open `CashSession` is required (inherited unchanged from `RevenueService::postToLedger()`).
- Multiple purchases by the same employee on the same date are legitimate by design (no Student-style `ServiceCoverage` overlap guard); only an accidental replay of the exact same `idempotency_key` + canonical payload hash is suppressed — a new key legitimately allows a second identical-looking purchase.
- **New narrow permission `manage employee stolovaya`** — deliberately **not** `manage revenues`/`post revenues` (granting those to cashier would have widened cashier into the entire generic Revenue surface, which the owner explicitly rejected). Intended operational access: `super-admin`, `admin`, `school-admin`, `principal`, `accountant`, `cashier`. `reception` and `teacher` are explicitly excluded. Cashier's generic `manage revenues`/`post revenues` access is unchanged (still absent) — verified both in automated tests and live in UAT.
- **Owner-approved revenue-integrity corrective (same PR, commit `df4322b7`):** independent review found that once `school_food` is active, it became generically selectable through the unstructured "Прочий приход" revenue form — a second, unstructured path to post a `school_food` entry with no `StaffFoodPurchase` behind it. Corrected: `school_food` is now excluded from the generic revenue form's category options (matched by stable `code`, never mutable `name_ru`), and a new `RevenueEntryController::forbidControlledCategory()` boundary check rejects any crafted generic-form POST carrying it — before `RevenueService::create()` is ever called, so a rejection creates zero `RevenueEntry`/`CashTransaction`. The trusted Employee Stolovaya path (`EmployeeFoodPurchaseService` → `RevenueService::createTrusted()`) is unaffected — it never goes through this controller at all. `RevenueService`'s generic authorization (`create()`/`post()`/`reverse()`/`deleteDraft()`) is completely unchanged.
- `school_food` `RevenueCategory` activation: a strict, code-matched data migration for already-provisioned databases (never touches `buffet`/`cafeteria`, never creates a row) plus a seeder default change for fresh installs.
- Tests: 27 focused tests (`EmployeeStolovayaPhase2Test`) plus 18 in `FinanceFoodBuffetCategorySeparationTest` (both independently re-run and passing) — permission gating, employee eligibility, FeePrice-not-MealPlan.price pricing, quantity validation, owner-account and closed-cash-session rejection, category tamper-resistance (both the new generic-form guard and the underlying trusted path), exactly-one-RevenueEntry/CashTransaction, idempotency replay/reject/new-key, forced-failure rollback, and the school_food activation migration's both lifecycle scenarios (pre-existing row flips; fresh-install no-op then seeder-creates-active). Student Stolovaya, Buffet, generic Revenue, `RevenueAtomicCreationTest`, and full payroll regression suites all re-confirmed green throughout. Full Finance suite: 1918 passed, 14 skipped, 2 known pre-existing unrelated baseline failures (`EnumMigrationPortabilityTest`, `TuitionPaymentPeriodAmbiguityGuardTest`), independently inspected and confirmed unrelated to this change.

**Deployment/UAT preparation (recorded accurately — this is what actually happened, not a recommended deployment strategy):**

PR #86's merge was automatically deployed to UAT, but the initial deployment did **not** apply the two new Phase 2 migrations (`2026_09_26_120000_create_staff_food_purchases_table`, `2026_09_26_120100_activate_school_food_revenue_category`) or the new `RolesAndPermissionsSeeder` permission grant — this project's deploy pipeline runs `migrate --force` but does not automatically re-run seeders, and in this instance the migrations themselves were also initially left pending. This surfaced in two separate read-only investigations before any Employee Stolovaya purchase was ever attempted:
- **Permission gap:** `manage employee stolovaya` was missing/ungranted — confirmed by cross-referencing exact permission counts across all 8 roles against the seeder code (every role's count matched "current code minus exactly this one permission," with zero unexplained deltas). The owner performed a **narrow, additive-only UAT data corrective** — `Permission::firstOrCreate(...)` + `givePermissionTo(...)` per role, explicitly **not** `syncPermissions()` (which would have risked silently reverting any other permission drift) — resulting in Permission ID **63**, granted to `super-admin`/`admin`/`school-admin`/`principal`/`accountant`/`cashier` and confirmed **not** granted to `reception`/`teacher`.
- **Migration gap:** the first live attempt at Scenario 1 (below) failed with an HTTP 500 because `staff_food_purchases` did not yet exist. Read-only diagnosis confirmed no financial residue from that failed attempt. The owner then ran `php artisan migrate --force` against UAT; both pending migrations completed successfully. Post-migration read-only verification confirmed `staff_food_purchases` exists, `school_food` (`RevenueCategory` ID 5) is active, and all Employee Stolovaya baseline counts were exactly zero before the successful retry.

**Browser UAT: PASSED / CLOSED.**

Performed against deployed merge commit `50eb5a6ac66709e8ad0388f7573d1c10d8d7c3fb`, after the migration and permission correctives above. Employee: ID 4 — Сотрудник приёмной UAT. Cash account: ID 1 — UAT — Основная касса (open `CashSession` confirmed).

Verified scenarios (all on food date 2026-09-26):

1. **Обед**, quantity 1, unit price 150.00 EGP, total 150.00 EGP, cash. `StaffFoodPurchase` ID 1, `RevenueEntry` `REV-2026-000003`, exactly one incoming `CashTransaction` +150.00 EGP. *(First attempt of this scenario failed with an HTTP 500 due to the pending migration above — zero financial residue confirmed read-only; after the owner ran the pending migrations, this scenario was retried exactly once and passed. This was a deployment/migration gap, not an application-flow defect.)*
2. **Напиток**, quantity 2, unit price 10.00 EGP, total 20.00 EGP, cash, fresh server-generated idempotency key. `StaffFoodPurchase` ID 2, `RevenueEntry` `REV-2026-000004`, exactly one incoming `CashTransaction` +20.00 EGP.
3. **Напиток** again — same employee, same date, same MealPlan as scenario 2 — quantity 1, unit price 10.00 EGP, total 10.00 EGP, cash, a fresh server-generated idempotency key distinct from scenario 2's. `StaffFoodPurchase` ID 3, `RevenueEntry` `REV-2026-000005`, exactly one incoming `CashTransaction` +10.00 EGP. Directly proves the approved business rule live: the same employee/date/MealPlan combination is not blocked when it originates from a fresh form/new idempotency key — no Student-style overlap guard applies here.

Final verified UAT delta: 3 `StaffFoodPurchase` records (IDs 1–3), 3 Employee Stolovaya `RevenueEntry` records (`REV-2026-000003/4/5`), exactly 3 incoming Employee Stolovaya `CashTransaction`s, cumulative collected **180.00 EGP** (150 + 20 + 10). No duplicate or unexpected record at any step; earlier scenarios' records remained intact and unchanged throughout. Student accounting (`Invoice`/`InvoicePayment`/`ServiceCoverage`) and payroll (`TeacherSalary`/`PayrollAdjustment`) remained isolated — no new records of either kind appeared anywhere in the UAT evidence.

**EMPLOYEE CASH STOLOVAYA PHASE 2 — BROWSER UAT PASSED — CLOSED.**

**Known separate issue (not caused by, and not blocking, this closure):** `/dashboard/finance/income/revenue/index` currently returns an HTTP 500 on UAT. Root cause has **not** been established (no server-log access was available during this investigation) — code-level tracing confirms the executing controller action and view are unmodified by PR #86, but this is not proof of an unrelated cause either. It did **not** block any part of the Employee Stolovaya create/preview/post/receipt workflow. Tracked as a separate, open investigation item — see `docs/06_Roadmap.md`.

**Non-blocking follow-ups (do not reopen Phase 2 for these):**
- `RevenueService::createTrusted()` public-method visibility — protected today only by convention and one architectural test, not a runtime guard. Recorded as technical debt, not redesigned.
- Idempotency-race concurrency path (`EmployeeFoodPurchaseService`'s `QueryException` catch) has no dedicated deterministic test; this codebase's only precedent for genuine concurrency testing (`InvoiceIssuancePostgresConcurrencyTest`-style, real `pcntl_fork()` against PostgreSQL) targets a different, Postgres-specific bug class and isn't reachable in this environment.
- `staff_food_purchases.unit_price`/`total_amount` decimal precision, hardcoded RU-only UI labels — both minor, non-blocking (see PR #86 review history).

## 2026-09-22 — Finance: Student Stolovaya daily-meal window (Phase 1) — Browser UAT PASSED, CLOSED

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

**Browser UAT: PASSED / CLOSED.**

Manual browser UAT was performed against deployment of merge commit `eee4d7875a3e3f07d7fa2e3221bf87240539d9ea` (UAT environment `env-a280cfcb-7231-4ecd-8173-0dccce3ffd8c`, deployment `depl-a2cd6979-6e70-4c54-81f0-12f8acfac0c6`). Student: ID 4 — Проверкина04 Анна.

Verified scenarios:

1. **2026-09-22 — Обед**, 150 EGP, paid cash. Invoice 27 / `INV-2026-000027`, InvoiceItem 93, ServiceCoverage 27, `option_value=3`, FeePrice 32, Payment 18, CashTransaction 23.
2. **2026-09-22 — Напиток**, 10 EGP, paid cash, allowed on the same date as Обед (same-day, different-MealPlan overlap-guard corrective verified live). Invoice 28 / `INV-2026-000028`, ServiceCoverage 28, `option_value=6`, Payment 19; global CashTransaction count increased correctly (the exact second CashTransaction id was not independently captured in the final UAT evidence and is not recorded here).
3. **Duplicate Обед on 2026-09-22** — correctly rejected with a meal-specific Russian message referencing `INV-2026-000027`. Zero financial delta.
4. **2026-09-23 — Суп**, 50 EGP, unpaid/debt. Invoice 29 / `INV-2026-000029`, ServiceCoverage 29, `option_value=4`, no Payment, no CashTransaction.

Final verified UAT delta: Invoices 0 → 3, ServiceCoverages 0 → 3, Payments 0 → 2, CashTransactions (global) 20 → 22, RevenueEntries (global) 2 → 2 (untouched). Total charged 210 EGP; total collected 160 EGP; outstanding debt 50 EGP.

Architectural facts reconfirmed live in this UAT run: `FeePrice` is authoritative for pricing; quantity is fixed at 1 in Phase 1; different MealPlans for the same student/date are allowed; the same MealPlan for the same student/date is blocked; Student Food uses Invoice/InvoiceItem/ServiceCoverage plus optional Payment/CashTransaction; Student Stolovaya never creates a `RevenueEntry`; Buffet remains a separate, unaffected flow.

**STUDENT STOLOVAYA PHASE 1 — BROWSER UAT PASSED — CLOSED.**

**Non-blocking follow-ups (do not reopen Phase 1 for these):**
- Food Fee uniqueness guard.
- Inactive `MealPlan` edge case.
- `MealPlan::sellableFood()` FeePrice academic-year/`is_active` scoping.
- Quick Registration meal-filter duplication/refactor (share logic with `sellableFood()`).
- Client-side preview/date-state robustness — UAT observed a malformed manual date edit could temporarily leave the submit button in a stale disabled client-side state until page reload; no server submission or financial residue occurred.
- This Phase 1 UAT covers only the Stolovaya card → student flow; other Food workflows (Quick Registration, Unified Collection) are not marked complete by this run and retain their own open browser-UAT item (see `docs/06_Roadmap.md`).

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
