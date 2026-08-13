# Finance

> STATUS: Placeholder scaffold. Expand over time.
> This is the highest-risk module. Treat every change as high blast-radius.

## Purpose

Authoritative reference for all monetary flows: invoices, installments,
payments, cash sessions/reconciliation, mass billing, teacher salaries, and
subscription lifecycle.

## Financial Safety Rules (non-negotiable)

- Money is sacred; correctness outranks convenience and speed.
- Never optimize at the expense of accuracy.
- Never mutate or delete historical financial records — correct with new records.
- Every flow must be auditable and reconcilable.
- Confirm before any irreversible financial operation.

## Key Components

_TODO: map each with file paths._

| Concern | Service / Model | Notes |
| ------- | --------------- | ----- |
| Invoice payment | `app/Services/Finance/InvoicePaymentService.php` | _TODO_ |
| Invoice | `app/Models/Invoice.php` | _TODO_ |
| Installments | `app/Models/InvoiceInstallment.php` | _TODO_ |
| Payments | `app/Models/InvoicePayment.php` | _TODO_ |
| Cash transactions | `app/Models/CashTransaction.php` | _TODO_ |
| Mass billing | `app/Http/Controllers/Dashboard/MassBillingController.php` | _TODO_ |
| Cash sessions / reconciliation | _TODO_ | See docs/FINANCE_PHASE3_CASH_SESSIONS.md |
| Teacher salaries | `app/Filament/Resources/TeacherSalaries/*` | _TODO_ |

## Tariff validity & advance invoicing

Durable business rule (see ADR-015):

- A `FeePrice` (tariff) belongs to a school year via `academic_year_id`. That
  field — not the calendar — is what scopes which year a price applies to. The
  tariff's `start_date`/`end_date` are a **version validity window** used only to
  pick among successive price versions on a given pricing date.
- Tuition tariffs for a **future** academic year may become invoiceable from
  their rollover/publication date. The academic-year **start date must never
  block advance invoicing or prepayment** — parents can receive invoices and pay
  tuition months before classes begin.
- Rolling a price list into a new year (`AcademicYearTariffRolloverService::copy`)
  opens each copied tariff's window at rollover time:
  `start_date = min(today, target.start_date)`, `end_date = target.end_date`. The
  `min()` clamp means the window is never opened *after* classes have begun and
  never runs past the year end.
- **Historical invoice snapshots remain immutable.** Each `InvoiceItem` stores its
  own `amount`/`unit_price` plus `tariff_valid_from`/`tariff_valid_to`; changing a
  tariff (or opening its window earlier) never recomputes an already-issued
  invoice. Corrections are additive (ADR-009), never in-place edits.

## Invoice Lifecycle

_TODO: draft → issued → partially paid → paid → refunded/void, with allowed transitions._

## Cash Session Reconciliation

_TODO: opening float, transactions, expected vs counted, variance handling._

## Correction Patterns

_TODO: how to reverse/adjust without deleting history._

## Related Docs

- `docs/FINANCE_PHASE3_CASH_SESSIONS.md`
- `docs/ai-knowledge/DECISIONS.md` (finance decisions)

## Open Questions / TODO

- [ ] Document the 4-phase finance corrections plan status.
- [ ] Enumerate money rounding / currency rules.
