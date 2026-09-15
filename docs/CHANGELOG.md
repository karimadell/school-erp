# Changelog

> Record of major features/fixes as they land on `recovery/full-work-2026-07-31`. Newest entries first. Each entry names the PR/commit, what changed, what was verified, and any known non-blocking follow-up — never silently omitted.

---

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
