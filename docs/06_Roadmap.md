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

### Still open / next

**P1**
- Classic `StudentInvoiceController` invoice creation still needs idempotency / duplicate-submit protection (Charge & Collect and Quick Registration already have it; the classic invoice-creation path does not).

**P2**
- Existing Student tab on the Quick Registration screen remains primarily a search-and-redirect card, not a guided in-place workflow — discoverability issue, not a functional gap.
- Returning-student / new-academic-year workflow is only partially integrated: `Enrollment` support exists, but re-enrolling a student into a new academic year and charging that year's services is not yet one guided Finance workflow.
- Classic Uniform purchase needs better quantity / multiple-item support (Quick Registration's multi-item Uniform selection is not yet mirrored elsewhere).
- Student Financial Account needs clearer per-academic-year financial summaries.
- Review/document the Food full-refund → same-date-repurchase behavior: a fully refunded Food invoice stays open/unpaid (not cancelled) unless explicitly voided, so its immutable coverage continues to block repurchasing the same dates until the invoice is explicitly voided. This is the current, safe (fail-closed) behavior — not automatically released on refund. See `docs/CHANGELOG.md` (2026-09-15 entry).
