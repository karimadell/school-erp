# Roadmap

> Status/priority tracker for in-progress modules. Update whenever a major feature lands — see `docs/CHANGELOG.md` for the dated history of what actually shipped. This file only tracks current status and what's next, not implementation detail.

---

## Finance

**Overall status: NOT complete.** Finance UAT is still ongoing; accountant UAT is still pending. Do not treat any item below as a signal that Finance as a whole is done.

### Completed

- Existing-student new Food purchase / coverage extension through Charge & Collect (PR #52).
- An existing student can purchase Food without repeating Quick Registration.
- New Food purchases create a new invoice/coverage and preserve historical Food invoices/coverage unchanged.
- Overlap protection exists for Food coverage (same student/Food fee, date-range overlap rejected safely).
- `AcademicCalendar` / `FoodBillableDayCalculator` logic is reused for every Food purchase path (Quick Registration and Charge & Collect) — no second pricing engine.
- Food 2026/2027 master data & pricing corrective (Phase 4B `option_value` identity migration + `finance:correct-food-2026-2027` price/`payment_period` correction) is closed, UAT-verified, and idempotent. See `docs/CHANGELOG.md` (2026-09-21 entry). The legacy whole-year command `finance:correct-2026-2027-prices` remains unfixed and is not the operational Food path.
- **Student Stolovaya daily-meal window (Phase 1) — CLOSED, Browser UAT PASSED:** a dedicated Столовая card and per-student single-day meal screen, reusing the existing Food accounting path (`ChargeAndCollectService`) end-to-end with authoritative `FeePrice` pricing — no second pricing/accounting engine, no `RevenueEntry`. Paid-cash and unpaid/debt flows both verified live in browser UAT; same-day different-MealPlan purchases verified allowed live; same-day duplicate-MealPlan rejection verified live with zero financial delta; `RevenueEntry` confirmed untouched throughout. Browser UAT passed on deployed SHA `eee4d7875a3e3f07d7fa2e3221bf87240539d9ea`. See `docs/CHANGELOG.md` (2026-09-22 entry).
- **Employee cash Stolovaya (Phase 2) — CLOSED, Browser UAT PASSED:** a dedicated cash-only purchase workflow for employees (`User` identity), reusing authoritative `FeePrice` pricing and the generic `RevenueEntry`/`RevenueService`/`CashTransaction` ledger via a narrow `manage employee stolovaya` permission (never the generic `manage revenues`/`post revenues`) and a new `StaffFoodPurchase` structured snapshot. `school_food` is now the dedicated, code-protected `RevenueCategory` for this flow — excluded from, and server-rejected on, the generic revenue form. Same employee/date/MealPlan repeat purchases verified allowed live (no Student-style overlap guard); 3 successful UAT purchases, 180.00 EGP cumulative, verified with zero duplicate/unexpected records and full Student/payroll isolation. Browser UAT passed on deployed SHA `50eb5a6ac66709e8ad0388f7573d1c10d8d7c3fb` (PR #86). See `docs/CHANGELOG.md` (2026-09-26 entry) — including the accurately-recorded initial migration/permission deployment gap and its correctives.

### Still open / next

**Next go-live priority** (with both Stolovaya Phases 1 and 2 now closed): real accountant Finance UAT and the remaining go-live validation — fixing only genuine P0/P1 operational or accounting blockers (starting with the `revenue/index` 500 below), operational data cleanup/imports, and production/go-live preparation. Not a new feature phase.

**P1**
- Classic `StudentInvoiceController` invoice creation still needs idempotency / duplicate-submit protection (Charge & Collect and Quick Registration already have it; the classic invoice-creation path does not).
- `/dashboard/finance/income/revenue/index` currently returns an HTTP 500 on UAT. Root cause not yet established (no server-log access during investigation); code-level tracing found the executing action/view unmodified by PR #86, but that is not proof of an unrelated cause. Does not block Employee Stolovaya (Phase 2) or any other verified flow, but must be investigated and fixed before final go-live if this page is operationally required (it is the only page that lists/filters generic `RevenueEntry` records, including Buffet/Donation/Other history).

**P2**
- Browser-based UAT of the modern Food purchase flows (Quick Registration / Unified Collection) against the corrected 2026/2027 prices has not yet been performed. (Student Stolovaya Phase 1's own browser UAT is closed — see Completed above — but that does not cover these other Food workflows.)
- **Next Stolovaya item — Phase 3: Employee salary-deduction settlement.** Employee cash Stolovaya (Phase 2) is now closed (see Completed above). Phase 3 remains pending explicit product design for the payroll deduction/reversal lifecycle — in particular, behavior once a payroll run has already reached `approved`/`paid` status. Not started; do not implement without that design decision.
- Stolovaya Phase 1 non-blocking technical follow-ups (do not reopen Phase 1 for these): Food Fee uniqueness guard; inactive `MealPlan` edge case; `MealPlan::sellableFood()` FeePrice academic-year/`is_active` scoping; Quick Registration meal-filter duplication/refactor; client-side preview/date-state robustness (UAT observed a malformed manual date edit could leave the submit button in a stale disabled state until page reload, with no server submission or financial residue).
- Stolovaya Phase 2 non-blocking follow-ups (do not reopen Phase 2 for these): `RevenueService::createTrusted()` public-method visibility (convention/test-protected only, not a runtime guard); idempotency-race concurrency path has no dedicated deterministic test (no reachable PostgreSQL precedent environment); `staff_food_purchases` decimal precision and hardcoded RU UI labels.
- Existing Student tab on the Quick Registration screen remains primarily a search-and-redirect card, not a guided in-place workflow — discoverability issue, not a functional gap.
- Returning-student / new-academic-year workflow is only partially integrated: `Enrollment` support exists, but re-enrolling a student into a new academic year and charging that year's services is not yet one guided Finance workflow.
- Classic Uniform purchase needs better quantity / multiple-item support (Quick Registration's multi-item Uniform selection is not yet mirrored elsewhere).
- Student Financial Account needs clearer per-academic-year financial summaries.
- Review/document the Food full-refund → same-date-repurchase behavior: a fully refunded Food invoice stays open/unpaid (not cancelled) unless explicitly voided, so its immutable coverage continues to block repurchasing the same dates until the invoice is explicitly voided. This is the current, safe (fail-closed) behavior — not automatically released on refund. See `docs/CHANGELOG.md` (2026-09-15 entry).
