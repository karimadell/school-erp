# Finance v1 — Phase 3: Cash Session Opening / Closing / Reconciliation

Status: **implemented, uncommitted** on branch `recovery/full-work-2026-07-31`.
Builds on Phase 0 (safety lockdown), Phase 1 (void + refunds), Phase 2 (charge & collect).

A *cash session* (кассовая смена) brackets one cashier's shift on one cash-type
drawer. It opens with a system-derived expected baseline, gathers the cash
movements posted while it is open (linked by FK at creation), and closes with a
single counted total that the system reconciles against the expected cash to
surface a shortage/overage. **A closed session is immutable.**

## Business behaviour

- **Strict cash-session rule.** A cash collection (`payment_method = cash` into a
  `type = cash` drawer) requires an open session. No cash payment can be recorded
  outside one — enforced centrally in `InvoicePaymentService`, so the canonical
  payment path *and* charge & collect both obey it. Non-cash methods
  (bank/card/transfer) are unchanged and need no session.
- **Opening baseline provenance.** The expected opening balance is derived, never
  a free-form amount: the previous closed session's **counted** total
  (`previous_session`), or the trusted account balance for the first-ever shift
  (`account_balance`). Provenance is stored on every session.
- **Attribution by FK-at-creation.** Every new cash movement stores its
  `cash_session_id` and `created_by` at creation. Historical rows are left null —
  **no retroactive backfill**.
- **Reconciliation math.** `expected_cash = opening_expected + session cash in −
  session cash out`. Only this session's own cash movements count; card/bank rows
  (never attached to a session) are excluded. The owning `CashAccount.balance` is
  **never** mutated by opening or closing.
- **Variance.** Tolerance is `0.00`. Any non-zero variance is shown as
  недостача (shortage, `< 0`) or излишек (overage, `> 0`), requires a Russian
  reason, and records `closed_by` / `closed_at` immutably. Closing with a variance
  requires the dedicated higher-risk permission. **No auto-posted adjustment, no
  silent balance correction.**
- **Immutability.** Closed sessions cannot be edited, reopened, or deleted
  (model-level guard + service checks). Corrections are a future controlled
  workflow, out of Phase 3 scope.
- **Cash refunds require an open session.** A cash refund is a physical cash
  outflow, so it needs an open shift on the drawer (resolved under lock; rejected
  + rolled back atomically if none — no orphan cash transaction). Non-cash
  refunds are exempt.
- **Financial-history data safety (DB-level).** `cash_sessions.cash_account_id`
  and `cash_transactions.cash_session_id` use `restrictOnDelete`: a cash account
  with sessions, and a session with transactions, cannot be hard-deleted through
  a DB cascade — immutable history and its attribution are preserved without
  relying on Eloquent events. `cash_transactions.created_by` keeps `nullOnDelete`
  (a user may be removed; the shift link stays the accountability anchor).

## Permissions

| Permission | Granted to |
|---|---|
| `view cash sessions` | super-admin, admin, school-admin, principal, accountant, cashier |
| `open cash sessions` | super-admin, admin, school-admin, principal, accountant, cashier |
| `close cash sessions` | super-admin, admin, school-admin, principal, accountant, cashier |
| `close cash sessions with variance` | super-admin, admin, school-admin, principal |

Teachers and reception get **no** cash-session write access. A cashier/accountant
runs the drawer day to day but cannot accept a variance — that is reserved for
finance/admin leadership.

## Russian labels

`Кассовая смена`, `Открыть смену`, `Закрыть смену`, `Касса`, `Кассир`,
`Остаток на начало`, `Приход`, `Расход`, `Ожидаемый остаток`,
`Фактический остаток`, `Расхождение`, `Недостача`, `Излишек`,
`Причина расхождения`, `Смена открыта`, `Смена закрыта`,
`История кассовых смен`. Full keys in `lang/{ru,en,ar}/cash_sessions.php`
(Russian authoritative).

## Files

- Model: `app/Models/CashSession.php` (+ relations on `CashTransaction`,
  `CashAccount`, `User`).
- Service: `app/Services/Finance/CashSessionService.php`
  (`open` / `close` / `activeFor` / `resolveOpeningExpected`).
- Enforcement: `app/Services/Finance/InvoicePaymentService.php` (cash requires a
  session; stamps `cash_session_id` + `created_by`),
  `app/Services/Finance/InvoiceRefundService.php` (a cash refund **requires** an
  open session — resolved under lock, stamped at creation; rejected and rolled
  back atomically if none; non-cash refunds exempt).
- Controller: `app/Http/Controllers/Dashboard/CashSessionController.php`.
- Requests: `app/Http/Requests/OpenCashSessionRequest.php`,
  `app/Http/Requests/CloseCashSessionRequest.php`.
- Routes: `dashboard.cash.sessions.{index,create,store,show,close}`.
- Views: `resources/views/dashboard/cash/sessions/{index,create,show}.blade.php`
  + sidebar entry «Кассовые смены».
- Migrations: `..._110000_create_cash_sessions_table.php`,
  `..._110100_add_session_and_actor_to_cash_transactions.php`.
- Tests: `tests/Feature/Finance/CashSessionTest.php` (23 tests).

## Scope boundaries (explicitly NOT implemented)

Denomination counting; bank/card reconciliation; automatic ledger adjustment;
reopening closed sessions; retroactive backfill; multi-currency drawers;
auto-close/timeout; hardware integration; GL/double-entry; Phase 4 reports;
recurring billing.
