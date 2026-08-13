# Decisions (ADR Log)

> STATUS: Living log. Newest at the top. One decision per entry.
> These are the durable architectural/schema decisions worth preserving for
> maintenance or a from-scratch rebuild. Each cites the code that proves it.

## Template

```
### ADR-NNN — Short title (YYYY-MM-DD)
- Status: Proposed | Accepted | Superseded by ADR-XXX
- Context: the problem / forces at play.
- Decision: what we chose.
- Consequences: trade-offs, follow-ups.
```

## Entries

### ADR-015 — Tariffs open for prepayment at rollover, not on year start (Accepted)
- Context: A `FeePrice` already separates *which* year it belongs to
  (`academic_year_id`) from *when the version is valid* (`start_date`/`end_date`).
  Invoicing scopes on the year; the calendar window only selects among versions.
  But `AcademicYearTariffRolloverService::copy()` pinned every copied tariff's
  `start_date` to the target year's start (1 Sept), so a rolled-over price was
  unpayable until classes began — blocking legitimate advance invoicing and
  tuition prepayment that schools do months ahead.
- Decision: Rolled-over tariffs open their window at rollover time —
  `start_date = min(today, target.start_date)` — while `end_date` still runs to
  the year end. The academic-year start date must never gate invoicing or
  prepayment; `academic_year_id` alone scopes which year a price belongs to. The
  `min()` clamp guarantees the window never opens *after* classes begin (safe for
  a late/mid-year rollover) and never past year end. Price versioning, resolution
  logic, and model/request validation are unchanged.
- Consequences: Tuition for a future year is invoiceable and prepayable as soon
  as its price list is rolled/published. Historical invoice snapshots remain
  immutable — each invoice item carries its own amount and `tariff_valid_from/to`,
  so opening the window earlier never recomputes a past invoice. See FINANCE.md
  §"Tariff validity & advance invoicing", ADR-009.

### ADR-014 — Validate new writes, never reconcile history (Accepted)
- Context: Curriculum/year rules were introduced long after data existed. Applying
  them to old rows would retroactively invalidate valid historical records.
- Decision: Every academic validation observer fires on `creating()` only, never
  `updating()`. A pre-existing non-compliant row is never rejected because an
  unrelated field is later edited. Legacy fallbacks (e.g. Exam→quarter year
  derivation) are read-only and one-directional — never inferred backward as a
  side effect.
- Consequences: New rules are safe to add without a data-migration/backfill of
  history. Backfills, when wanted, are explicit and separate. See ACADEMIC.md §11.

### ADR-013 — Historical academic years are locked, not mutated; unlocks expire (Accepted)
- Context: Grades, attendance, exams, and assignments must be frozen once a year
  closes, but occasional corrections are unavoidable.
- Decision: `ResolvesAcademicYear` + `AcademicYearLockObserver` block writes to
  non-active years centrally (one enforcement point, not per-controller).
  `resolveAcademicYear() === null` fails closed. Corrections require an
  `AcademicYearUnlock` with a required reason and a required `expires_at` (no
  permanent unlock); the only bypass is a narrow, per-call
  `AcademicYearLock::withoutLock()` for seeders/tests.
- Consequences: A new resource writing a year-scoped model is locked automatically
  by implementing the interface. Central enforcement replaced a per-controller
  rule that one resource had silently skipped. See ACADEMIC.md §2.

### ADR-012 — At most one active academic year, switched atomically (Accepted)
- Context: The active year is a global switch that changes what every
  authorization/curriculum/lock check sees.
- Decision: `AcademicYear::save()` deactivates all other active years inside one
  `lockForUpdate` transaction with its own save, guaranteeing 0-or-1 active years.
- Consequences: Year rollover is a single, safe operation. Everything downstream
  can assume one active year. See ACADEMIC.md §2.1.

### ADR-011 — Teaching authority = year-scoped TeacherAssignment, not qualification (Accepted)
- Context: "Can teach subject X" (a durable skill) is different from "may teach X
  to class Y this year" (an authorization).
- Decision: Three separate links: `teacher_subject` (qualification, year-agnostic),
  `teacher_assignments` (authorization, `unique(teacher,class,subject,year)`), and
  `class_teachers` (homeroom). The Teacher Portal and timetable teacher selection
  use `currentAssignments()` (active year only); qualification is never consulted
  for authorization. Teacher pages re-check `isAssignedToClass(Subject)` at write
  time to defeat tampered payloads.
- Consequences: A prior year's assignment never grants current-year access;
  ownership is re-verified on every mutation (IDOR defense). See ACADEMIC.md §5,§10.

### ADR-010 — Curriculum is the single gate for offer/schedule/grade/elect (Accepted)
- Context: Several features independently needed "is this subject valid for this
  class this year".
- Decision: `Curriculum` (AcademicYear×Grade×Subject, grade-level only) is the sole
  source; `CurriculumContext` is the single resolver. Timetable dropdowns, the
  conflict rules, exam/grade validation, and elective validation all consult it.
  Class-level overrides are deliberately deferred/undesigned.
- Consequences: Seeding a new year requires curricula first — everything academic
  fails closed without them. One place to change "what a grade studies". See
  ACADEMIC.md §4,§14.

### ADR-009 — Immutable financial history: cancel/refund add rows, never mutate (Accepted)
- Context: Invoices, payments, and cash movements are legally/operationally
  auditable. Editing or deleting them destroys the audit trail.
- Decision: Voiding an invoice sets `cancelled_at/by/reason` and flips status but
  leaves number/items/totals untouched. Refunds are a separate immutable
  `payment_refunds` row referencing the original payment — **never** a negative
  `InvoicePayment` (that legacy flow is disabled). Cash sessions are immutable
  once closed. Finance FKs use `restrictOnDelete` so history can't be wiped by
  deleting a parent.
- Consequences: Reads must aggregate event rows to get "current" balances; a
  legacy-vs-canonical split exists during the transition (route comments warn
  "never call the legacy refund"). See FINANCE.md, DATABASE.md §7.

### ADR-008 — Idempotency key + human document number on every money write (Accepted)
- Context: Cashier flows can be double-submitted; retries must not create
  duplicate money.
- Decision: Each money entity carries a unique idempotency key
  (`idempotency_key` uuid + `idempotency_hash`) AND a human-readable unique
  document number (`INV-…`, `PAY-…`, refund/receipt numbers). Mass-billing uses
  `billing_runs.uuid` and a unique `billing_run_items.invoice_id`. The DB unique
  index is the last line of defense behind the service-layer guard.
- Consequences: Safe retries; receipts have stable numbers. Requires generating
  both a number and a key on the write path. See DATABASE.md §4.

### ADR-007 — Portable DB constraints only; conditional rules in services/observers (Accepted)
- Context: The app targets MySQL in prod and SQLite in tests. CHECK constraints
  and conditional invariants don't express/port cleanly.
- Decision: The DB enforces only unique/FK/not-null. Conditional rules
  ("reason required only when overriding a price", "close note required when
  variance ≠ 0", "no writes to a historical academic year") live in Services and
  Observers.
- Consequences: The schema alone under-specifies the domain — Observers/Services
  are part of the data model. Tests must exercise those layers, not just the DB.
  See DATABASE.md §3, ARCHITECTURE.md §7.

### ADR-006 — Cross-UI invariants belong in Observers, not controllers (Accepted)
- Context: The same model is written from a Blade controller, a Filament resource
  action, or a Filament page. There is no single controller all writes pass
  through.
- Decision: Invariants that must always hold (audit trail, academic-year write
  lock, curriculum validation, exam snapshotting) are Eloquent Observers
  registered in `AppServiceProvider::boot()`, with **deliberate registration
  order** (e.g. `ExamSnapshotObserver` before `AcademicYearLockObserver`).
- Consequences: One rule, enforced everywhere, regardless of UI. Observer
  ordering is load-bearing and must be preserved. See ARCHITECTURE.md §7.

### ADR-005 — One composed conflict-rule pipeline behind a Contract (Accepted)
- Context: Timetable conflict logic was at risk of being re-implemented per UI.
- Decision: `TimetableConflictRule` is an interface; 8 stateless rule objects are
  composed in an ordered list inside `AppServiceProvider` and resolved as
  `CurriculumAwareTimetableConflictChecker`. UIs resolve the checker; they never
  write conflict SQL. Order matters (working-day → curriculum → assignment →
  duplicate → teacher → class → room).
- Consequences: New checks are added in one place. This is the model pattern for
  any cross-cutting rule engine in a rebuild. See ARCHITECTURE.md §4.

### ADR-004 — `super-admin` is the bypass role, with a CRUD carve-out (Accepted)
- Context: Need a break-glass role without disabling model-level protections
  (e.g. "can't delete the last active super-admin").
- Decision: `super-admin` (not `admin`) bypasses permissions via BOTH
  `User::hasPermissionTo` and `Gate::before`, **except** the abilities in
  `User::protectedPolicyAbilities()` (the CRUD gate abilities), so model policies
  still run for super-admins. `admin` is just one of seven administrative roles.
- Consequences: Two copies of the bypass must stay in sync (a Filament page calls
  `hasAnyPermission()` outside the Gate). A prior memory note that said `admin`
  bypasses everything is inaccurate — trust the code. See ARCHITECTURE.md §5,
  PERMISSIONS.md.

### ADR-003 — Deny-by-default panel access; teacher portal needs a live teacher (Accepted)
- Context: Two Filament panels (admin, teacher) plus a Blade dashboard on one
  user table.
- Decision: `User::canAccessPanel` denies by default. Admin panel requires an
  administrative role; teacher panel requires role `teacher`, an active linked
  `teachers` row, and *not* holding an admin role (except super-admin). The Blade
  dashboard is gated by `EnsureAdministrativePortalAccess`, which redirects
  teachers to their panel and logs out inactive users.
- Consequences: Clear separation of the two audiences; teacher authorization is
  data-driven (`teacher_assignments`, active teacher row), not just role-based.

### ADR-002 — Enrollment is the year-scoping spine of the domain (Accepted)
- Context: Everything academic and financial is scoped to a school year.
- Decision: `enrollments` has `unique(student_id, academic_year_id)` and snapshots
  stage/grade/class at enrollment time. Attendance, invoices, subscriptions, and
  meal subscriptions hang off the enrollment, so "one student, one year" is a
  DB-guaranteed anchor. `academic_years.is_active` marks the current year.
- Consequences: Year rollover and historical locking have a single anchor. A
  rebuild should keep this spine. See DATABASE.md §6.2, §8.

### ADR-001 — Strictly additive migrations; only students are soft-deleted (Accepted)
- Context: Production data must never be destroyed; schema evolves continuously.
- Decision: 149 additive migrations, never rewriting history; columns are added,
  not renamed. Only `students` uses soft deletes; all other history is protected
  by FK `restrictOnDelete` rather than soft-delete flags. Non-destructive
  `down()` methods (restore NOT NULL only if no null rows exist).
- Consequences: Current table shape = create + all alters (read model casts for
  the effective shape). Migrations are guarded (`hasIndex`/`hasColumn`) because
  MySQL DDL isn't transactional. See DATABASE.md §1, §10.

### Repo-authored decision docs to migrate here later
- `docs/TIMETABLE_ARCHITECTURE_DECISIONS.md`
- `docs/OPEN_POLICY_DECISIONS.md`
- `docs/FINANCE_PHASE3_CASH_SESSIONS.md`
