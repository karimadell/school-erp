# School ERP — Project Command Center

> Living reference document. Built from the Phase 2 codebase inspection, the execution master plan, and the Sprint 0 implementation already completed in this project's working history. Update this file as sprints land — do not let it drift from reality.
>
> **Uncertainty marker used throughout:** ⚠️ *unconfirmed* — means inferred/recommended, not verified against a product decision or a real (non-SQLite-test) database.

---

## 1. Project goal

Bring this Laravel 12 School ERP (students, teachers, academic structure, timetable, attendance, exams, finance, and a bilingual/trilingual Arabic-Russian-English admin experience, split across a custom Blade dashboard and a parallel Filament admin panel) from its current partially-broken state to a genuinely production-ready system — without a rewrite, by systematically closing the gaps found during inspection.

## 2. Current production-readiness status

**Not production-ready.** Sprints 0 and 1 are merged into `main`. Sprint 2 (Attendance) is complete on its own branch, tested, pushed, not yet merged — see §3. Multiple modules are still either broken in specific surfaces (Filament `TimetableResource`, `TimetableGrid.php`) or entirely unimplemented (Exams, Report Cards). Attendance has zero authorization on any surface by design decision (deferred to Sprint 5, see §14 item 6). Railway deployment preparation (trusted proxies, database-backed sessions) was completed in an earlier work stream and is separate from the sprint numbering in this document.

## 3. Current Git status

| Item | Value |
|---|---|
| `main` branch commit (`origin/main`) | `bdef442` — "Merge pull request #2 from karimadell/sprint-1-academic-structure" |
| Sprint 0 | ✅ **Merged into `main`** via PR #1 (`386722b`). |
| Sprint 1 | ✅ **Merged into `main`** via PR #2 (`bdef442`), which also carried Sprint 0's commits (Sprint 0's own PR had not been merged separately by the time Sprint 1 was ready). |
| Sprint 2 branch | `sprint-2-attendance`, branched from `main` at `bdef442` |
| Sprint 2 commits | 7 commits, `961c765`..`8a12226` (full list with messages in §5) |
| Sprint 2 pushed? | ✅ Yes — pushed to `origin/sprint-2-attendance` |
| Sprint 2 merged into `main`? | ❌ **Not merged.** PR prepared (base `main`, compare `sprint-2-attendance`) but not created via `gh` (unavailable in the working environment) and not merged. |
| `sprint-2-attendance` ahead of `origin/main` | 7 commits (clean — branched after Sprint 0+1 were already merged, no bundling) |

---

## 4. Module status table

Legend for **Assigned sprint**: matches §5 below. **Suggested branch name** follows `sprint-N-<module-group>` so parallel teams never share a branch for unrelated modules within the same sprint.

| Module | Current status | Missing work | Priority | Dependency | Assigned sprint | Suggested branch | Tests required |
|---|---|---|---|---|---|---|---|
| **Students** | ✅ **Sprint 1 complete.** 2 dead controllers (`StudentsController.php` top-level + `Dashboard\StudentsController.php`, the latter an exact class-name collision with the live `Dashboard\StudentController`) deleted. Permission middleware added (`manage students`, reused the already-seeded permission) gating create/store/edit/update/destroy. **Follow-up bug found and fixed in the same sprint:** `students.name` was NOT NULL with nothing ever populating it — every student insert failed; fixed via a new nullable migration. | None outstanding from Sprint 1 scope. | — | Stages/Grades/Classes (enrollment) | Sprint 1 | `sprint-1-academic-structure` *(actual branch used)* | `StudentTest.php` — 5 tests, all passing |
| **Teachers** | ✅ **Sprint 1 complete.** `Dashboard\TeacherController` — CRUD + document upload/delete + PDF/Excel export all work. Permission middleware added (`manage teachers`, new permission) gating create/store/edit/update/destroy/storeDocument/deleteDocument. | None outstanding from Sprint 1 scope. | — | Subjects (pivot) | Sprint 1 | `sprint-1-academic-structure` *(actual branch used)* | `TeacherTest.php` — 5 tests, all passing |
| **Academic Years** | ✅ **Sprint 1 verification complete (Task 8).** No dashboard-side controller/route (confirmed intentional — zero functional dependency from Attendance, Timetable, Exams, Fees, Invoices; `Enrollment` uses a free-text `academic_year` field in practice, not the `AcademicYear` relationship). Filament-only path **accepted for current scope.** **Newly discovered, NOT fixed (Sprint 1 was read-only verification):** `AcademicYear` model has no `$fillable`, so it is totally guarded — creating/editing via Filament, and `AcademicYearSeeder` itself, throw `MassAssignmentException`. Filament form/table also reference a nonexistent `is_current` column (real column is `is_active`). See backlog (§14). | Fix tracked in backlog (§14), not scheduled to a sprint yet. | Medium *(breaks fresh-install seeding)* | — | Sprint 1 *(verification only)* — ✅ done | `sprint-1-academic-structure` *(actual branch used)* | None — verification-only, no code changed |
| **Stages** | ✅ **Sprint 1 complete.** Permission middleware added (`manage stages`, new permission) gating create/store/edit/update/destroy. **Follow-up bugs discovered, NOT fixed (out of scope for the middleware task) — see backlog (§14):** `StageController` has no `show()` method despite the resource route registering one; `store()` mass-assigns `order`/`is_active`, neither of which is in `Stage::$fillable`, so they're silently ignored; `update()` calls `$stage->update($request->all())`, passing the raw request instead of validated data. | Backlog items in §14. | Medium | — | Sprint 1 | `sprint-1-academic-structure` | `StageTest.php` (new) — 6 tests, all passing |
| **Grades** *(class-year, e.g. "Grade 1")* | ✅ **Sprint 1 complete.** `GradeController` works correctly (only ever writes `stage_id`+`name`, matching the real schema). `Grade` model's dead `order`/`is_active` fillable/cast entries (referenced nonexistent columns) removed. The duplicate, dead `2026_03_15_183112_create_grades_table.php` migration (guarded no-op, abandoned "Russian numeric score" schema) deleted. Permission middleware added (`manage grades`, new permission). | None outstanding from Sprint 1 scope. | — | Stages | Sprint 1 | `sprint-1-academic-structure` | `GradeTest.php` — 8 tests, all passing |
| **Classes** | ✅ **Sprint 1 complete.** `classes.name_ar` fillable gap fixed (was NOT NULL but missing from `SchoolClass::$fillable`, a live latent bug). Permission middleware added (`manage classes`, new permission). `class_rooms`/`ClassRoom` **audited in full (Task 7)** — confirmed a fully orphaned duplicate (zero routes/views/Filament/tests reference it; its own seeder is never called from `DatabaseSeeder`) with no unique business meaning. **Retirement deferred** — the actual drop needs a production pre-flight data check first (see §13 item 3 and backlog §14), not executed this sprint per the "do not delete/migrate" audit boundary. | Retirement scheduling — backlog item in §14. | — | — | Sprint 1 | `sprint-1-academic-structure` | `SchoolClassTest.php` — 7 tests, all passing |
| **Subjects** | ✅ **Sprint 1 complete.** `Dashboard\SubjectController`, resource-routed, CRUD works. Permission middleware added (`manage subjects`, new permission) gating create/store/edit/update/destroy. **Follow-up bug found and fixed in the same sprint:** `subjects.name_ar` was NOT NULL but missing from `Subject::$fillable` and never set by the controller either — every subject save failed; fixed by adding it to `$fillable` and auto-copying from `name_ru`, mirroring the `SchoolClass` fix. | None outstanding from Sprint 1 scope. | — | — | Sprint 1 | `sprint-1-academic-structure` *(actual branch used)* | `SubjectTest.php` — 5 tests, all passing |
| **Timetable** | `Dashboard\TimetableController` — full CRUD + conflict detection (class/teacher/room clash) + PDF export, generally solid. `show()`'s `Day::orderBy('order')` bug (nonexistent column) was **fixed in Sprint 0**. **Not touched by Sprint 2** — the sprint's original two-branch plan (`sprint-2-timetable` + `sprint-2-attendance`) was not followed; only the Attendance half was executed (see §5). `PeriodSeeder`/`Period::$fillable` (needed by both modules) were fixed as part of Sprint 2's Attendance work and are available for Timetable to build on. **Newly found during Sprint 2 planning (§14 item 7):** a *second*, unfixed instance of the `orderBy('order')` bug in `app/Filament/Resources/ClassResource/Pages/TimetableGrid.php` (Sprint 0 only fixed `TimetableController`); `TimetableResource` also references a nonexistent `teacher.name_ru` (a third broken column beyond the two `day`/`period` ones already known). | Filament `TimetableResource` still references a nonexistent `name_ru` column on `days`/`periods`/`teacher` — will error on create/edit in `/admin`. `TimetableGrid.php`'s `orderBy('order')` bug (separate from the one Sprint 0 fixed). No `Day` seeder exists (`Period` now seeded via Sprint 2's `PeriodSeeder`). `timetable.php` lang file missing in `ar`/`en` — Sprint 2 added a *minimal* version (just the `lesson` key Attendance itself needed); the module's full key set is still unbuilt. | High | SchoolClass, Subject, Teacher | Sprint 2 *(not started — see note)* | `sprint-2-timetable` | Extend `tests/Feature/TimetableTest.php` (Filament + seeder coverage) |
| **Attendance** | ✅ **Sprint 2 complete.** `Period` model's missing `$fillable` fixed (was totally guarded, blocked seeding) + new `PeriodSeeder`. Filament `AttendanceResource` form/table/infolist fixed (`type`/`period_id`/`attendance_key` fields added, `attendance_key` auto-generated via page hooks) and fully localized — the first localized Filament resource in the codebase. Teacher-panel `TeacherAttendance` rewritten against the real `enrollment_id`-based schema (was using a nonexistent `student_id` column, missing `attendance_key`, and an undefined `notify()` call) and fully localized (was 100% hardcoded Russian). Two missing views restored (`reports/student.blade.php`, `dashboard.blade.php`, both 500'd before). Status validation hardened (`attendance.*.*`/`attendance.*` wildcard rules) on both dashboard and teacher save paths. Full `attendance.php` localization (34 keys × 3 locales) plus minimal `ar`/`en` `timetable.php`. Navigation links added to the live nav (`layouts/dashboard.blade.php`); dead `create.blade.php` view deleted. | **Deferred to Sprint 5 (see §14 item 6):** zero permission middleware exists on any Attendance surface (dashboard, admin, teacher panel) — any authenticated user can take/edit attendance for any class. This was an explicit, confirmed scope decision, not an oversight. | — | Enrollment (fixed in Sprint 0) | Sprint 2 | `sprint-2-attendance` | `AttendanceTest.php` (9), `Admin/AttendanceResourceTest.php` (4), `Teacher/TeacherAttendanceTest.php` (7), `Unit/DatabaseSeederTest.php` (+2) — 22 new tests, all passing |
| **Exams** | `ExamController` is a **completely empty stub** — zero methods, despite a full resource route registration and an `Exam` model/migration existing. No views. No Filament resource. **0% implemented.** | Build the entire module: controller CRUD, views, Filament resource. | **Critical (build, not a bug fix)** | Subjects, `Quarter` (already working via StudentGrade) | Sprint 3 | `sprint-3-exams` | New `ExamTest.php` (full CRUD) |
| **Report Cards** | `ReportCardController` contains **unrelated restaurant-billing methods**, not report-card logic — the actual `report_cards.index/.show/.print` routes call nonexistent methods and will fatal-error. Views (`index/show/print.blade.php`) exist and are ready but unreachable. A separate, correctly-routed `ReportController` holds a stubbier duplicate of the same restaurant-report logic. | Rewrite `ReportCardController` against real `StudentGrade`/`Quarter` data; relocate or delete the restaurant-report duplication between `ReportController`/`ReportCardController`. | **Critical (build, not a bug fix)** | StudentGrade (already working), Quarter | Sprint 3 | `sprint-3-report-cards` | New `ReportCardTest.php` |
| **Fees** | `FeeController`/`FeePriceController` (dynamic pricing) exist, validation present in each. | Add permission middleware (none exists). | Medium | Students | Sprint 4 | `sprint-4-finance-fees-invoices` | New `FeeTest.php` |
| **Invoices** | `InvoiceController` (532 lines) — large, functional for its core path. Contains a call to `JournalEntry::create()`/`JournalItem::create()` inside `recordInvoicePayment()` — `App\Models\JournalEntry` **does not exist as a file**, but this is guarded behind `Schema::hasColumn('cash_accounts','account_id')`, and that column **does not exist either**, so the guard is always false and this code path is confirmed dead/unreachable, not a live crash. | Decide: delete the dead Journal/Account subsystem entirely, or implement it properly (product decision, see §13). Add permission middleware. | Medium-High | Fees, CashAccount | Sprint 4 | `sprint-4-finance-fees-invoices` | New `InvoiceTest.php` |
| **Payments** | Recorded via `InvoiceController::recordInvoicePayment()` and the `InvoicePayment` model — functions as part of the Invoices flow; not separately/independently audited beyond that. | Not deeply audited on its own ⚠️ *unconfirmed scope*; covered by whatever Invoice testing/hardening Sprint 4 does. | Medium | Invoices, CashAccount | Sprint 4 | `sprint-4-finance-fees-invoices` | Covered by `InvoiceTest.php` |
| **Cash** | `CashAccountController` (top-level) is the active, routed controller for accounts — its broken dot-notation permission checks (`cash.create`/`cash.edit`/`cash.delete`, never seeded) were **fixed in Sprint 0**, now correctly checking the seeded `manage cash` permission. `Cash\CashTransactionController`/`Cash\CashTransferController` (namespaced) are the active transaction/transfer controllers. Three duplicate dead controllers exist and are confirmed unreferenced anywhere: top-level `CashTransactionController.php`, top-level `CashTransferController.php`, and `Cash\CashAccountController.php`. | Delete the 3 dead duplicate controllers. Decide the Journal/Account subsystem's fate (shared with Invoices, see §13). | Medium | — | Sprint 4 | `sprint-4-finance-cash` | `tests/Feature/CashAccountTest.php` already exists and was expanded in Sprint 0 (16 tests) |
| **Roles and Permissions** | The `role` middleware alias (was entirely unregistered, fatal-erroring the whole `/dashboard/admin/*` section for every user) and the empty `Admin\RoleController` stub were both **fixed in Sprint 0**. `CashAccountController`'s permission-string mismatch was also fixed there. Two competing, unreconciled permission seeders still coexist: `RolesAndPermissionsSeeder` (active, phrase-style: `manage cash`, etc.) and `RolePermissionSeeder` (orphaned, never called, a completely different vocabulary: `view students`, roles `admin`/`accountant`/`cashier` vs. the active seeder's `admin`/`accountant`/`reception`). | Reconcile/delete the orphaned seeder. Add `canAccessPanel()` to `User` for both Filament panels (`/admin` and `/teacher`) — currently **any authenticated user can reach both**, confirmed, unfixed. Add Filament resource policies (only one Policy class exists app-wide, unrelated). | **Critical (access gap)** | — | Sprint 5 | `sprint-5-access-hardening` | New `PanelAccessTest.php`; `tests/Feature/Admin/AdminRouteAccessTest.php` already exists (3 tests, from Sprint 0) |
| **Dashboard** | `Dashboard\DashboardController` is the live, routed one. A duplicate, orphaned top-level `DashboardController` exists (dead, incompatible output variables even if it were ever wired). The live controller computes several variables (`cashDailyRaw`, `latestPayments`, `studentsByStage`, `studentsPerClass`, `attendanceRate`, `upcomingExams`, `todayRevenue`) that are **never used in the view** — wasted queries every page load. A `#cashChart` canvas exists with no corresponding JS chart call — renders permanently empty. | Delete the dead controller; prune unused query variables; wire up (or remove) the Cash Flow chart placeholder. | Low-Medium | Final data shapes from other sprints | Sprint 5 | `sprint-5-dashboard-cleanup` | New `DashboardTest.php` |
| **Localization** | `ar`/`en` are missing several files present in `ru` (`general.php`, `teachers.php`, `timetable.php`); `ar` is additionally missing `reports.php`. Confirmed key-count gaps: `ru/dashboard.php` is missing 10 keys that `ar`/`en` have; `ar`+`en`/`invoices.php` are both missing 21 keys that `ru` has. RTL is correctly applied on the main dashboard layout but **not** on the guest/auth layout (login/register pages aren't RTL for Arabic). | Fill every file/key gap; add RTL to the guest layout. | Medium | Every module's own translation keys | Sprint 5 | `sprint-5-localization` | New `LocalizationParityTest.php` (automatable key-parity check across all 3 locales) |

---

## 5. Sprint roadmap

### Sprint 0 — Critical unblockers
- **Goal:** Remove the handful of live-breaking bugs blocking every other sprint or already broken for every user.
- **Modules:** Roles and Permissions, Cash (CashAccountController only), Enrollment/Attendance (schema fix), Timetable (one query fix), Stages/Grades (seeder fix).
- **Tasks:** Register `role` middleware alias; implement `Admin\RoleController::index()`; fix `CashAccountController`'s permission strings; make `enrollments.academic_year_id`/`class_room_id` nullable; fix `TimetableController::show()`'s `order` column; fix `StageSeeder`/`GradeSeeder`. Also fixed along the way: audit-log view path, `AuditLog` model's disabled timestamps.
- **Dependencies:** None — this sprint unblocks everything else.
- **Expected files:** `bootstrap/app.php`, `app/Http/Controllers/Admin/RoleController.php`, `app/Http/Controllers/CashAccountController.php`, `app/Http/Controllers/Dashboard/TimetableController.php`, `app/Models/AuditLog.php`, `database/seeders/{Stage,Grade}Seeder.php`, one new migration, one moved view, one new view.
- **Shared files requiring merge coordination:** `bootstrap/app.php` (only Sprint 0 touches it — no conflict expected going forward unless a later sprint also needs a middleware alias).
- **Required tests:** `tests/Feature/Admin/AdminRouteAccessTest.php`, `tests/Feature/EnrollmentTest.php`, `tests/Feature/TimetableTest.php`, `tests/Unit/DatabaseSeederTest.php`, expanded `tests/Feature/CashAccountTest.php`.
- **Definition of done:** Full suite green; `route:list` clean for affected routes; no new log errors. *(All met.)*
- **Branch name:** `sprint-0-critical-unblockers`
- **Status:** ✅ **Complete — pushed to origin, not yet merged into `main`.**

### Sprint 1 — Foundation cleanup
- **Goal:** Remove dead code and close authorization gaps in the people/academic-structure modules.
- **Modules:** Students, Teachers, Academic Years *(verification only)*, Stages, Grades, Classes, Subjects.
- **Status:** ✅ **COMPLETE.** All 8 tasks finished (6 implementation tasks + 2 read-only audit/verification tasks). Full test suite green after every commit; no new `laravel.log` errors; `route:list` clean throughout.
- **Branch actually used:** `sprint-1-academic-structure` — a single branch for all Sprint 1 work (the two-branch parallel split originally suggested below was not followed; see §3).
- **Completed deliverables, in commit order** (all originally on `sprint-1-academic-structure`; ✅ merged into `main` via PR #2, `bdef442`):
  1. `010697c` — **`fix(classes): add name_ar to SchoolClass fillable`** — closed the live `classes.name_ar` NOT NULL / mass-assignment gap (the roadmap's highest-priority Sprint 1 item). New `SchoolClassTest.php`.
  2. `c962d6e` — **`fix(grades): remove dead order/is_active fields from Grade model`** — removed fillable/cast entries referencing nonexistent `grades` columns. New `GradeTest.php`.
  3. `dc0b5c1` — **`chore(grades): delete dead duplicate create_grades_table migration`** — removed the guarded no-op `2026_03_15_183112_create_grades_table.php`.
  4. `56b1e70` — **`chore(students): delete 2 dead Student controllers`** — removed `StudentsController.php` (top-level) and `Dashboard\StudentsController.php`, the latter found to declare a class-name-colliding `StudentController`, unreachable via PSR-4 but a classmap-optimization hazard.
  5. `57dde40` — **`feat(people): add permission middleware to Students/Teachers/Subjects`** — new permissions `manage teachers`, `manage subjects` (reused existing `manage students`); new `StudentTest.php`, `TeacherTest.php`, `SubjectTest.php`.
  6. `6e89429` — **`fix(subjects): add name_ar to Subject fillable, populate on save`** — follow-up fix for a bug discovered while writing Task 5's tests (same defect class as commit 1, this time on `Subject`); extended `SubjectTest.php`.
  7. `d287ee3` — **`fix(students): make students.name nullable`** — follow-up fix for a second bug discovered the same way (on `Student`); new migration, extended `StudentTest.php`.
  8. `2256a57` — **`fix(auth): protect academic structure routes with permissions`** — new permissions `manage stages`, `manage grades`, `manage classes`; new `StageTest.php`; extended `GradeTest.php`/`SchoolClassTest.php` with authorization coverage.
- **Task 7 (`class_rooms`/`ClassRoom` retirement) — audit complete, no code changed.** Confirmed fully orphaned (zero routes/views/Filament/tests/factories reference it; its own `ClassSeeder` is never called). Retirement **deferred** pending a production data pre-flight check (§13 item 3) — not a Sprint 1 blocker per the roadmap's own original scoping.
- **Task 8 (Academic Years) — verification complete, no code changed.** Confirmed zero functional dependency from every other module; the Filament-only path is **accepted as sufficient for current scope** — no dashboard-side CRUD needed. Surfaced a previously-unknown, unrelated bug (`AcademicYear` `MassAssignmentException`, breaks Filament create/edit and crashes `AcademicYearSeeder`) — logged to the backlog (§14), not fixed (out of scope for a read-only verification task).
- **Validation performed on every commit:** focused tests for the touched module, full `php artisan test` suite (ended at 73 passed / 161 assertions), `route:list` reviewed for errors, `storage/logs/laravel.log` line-count diffed before/after to confirm zero new errors, `git diff` reviewed for scope before each commit.
- **Definition of done:** met — full suite green; every listed controller CRUD confirmed still working post-cleanup; permission-denial tests (403 for unauthorized, success for authorized) pass for every module.

*Original plan (superseded by what's recorded above):*
- **Tasks:** Delete 2 dead Student controllers; fix `SchoolClass.name_ar` fillable gap; delete the dead duplicate `grades`-migration and `Grade` model's dangling `order`/`is_active`; audit and retire `class_rooms`/`ClassRoom` (only after confirming zero remaining dependents); add permission middleware across Students/Teachers/Subjects/Stages/Grades/Classes.
- **Dependencies:** Sprint 0 merged.
- **Expected files:** `app/Models/{Grade,SchoolClass}.php`, delete 2 controller files, a new drop-migration for `class_rooms` *(only after dependency audit)*, `routes/web.php` (permission middleware on these groups), `database/seeders/RolesAndPermissionsSeeder.php` (additive new permission names).
- **Shared files requiring merge coordination:** `routes/web.php`, `database/seeders/RolesAndPermissionsSeeder.php` — see §8.
- **Required tests:** New `StudentTest.php`, `TeacherTest.php`, `SubjectTest.php`, `StageTest.php`, `GradeTest.php`, `SchoolClassTest.php`.
- **Branch name:** `sprint-1-people-foundation` and `sprint-1-academic-structure` *(two branches — see §6, these can run as separate parallel workstreams within the same sprint)*

### Sprint 2 — Attendance and enrollment
- **Goal:** Make Timetable and Attendance fully functional across all three surfaces (dashboard, Filament admin, Filament teacher panel).
- **Modules:** Timetable, Attendance *(Enrollment's schema fix already landed in Sprint 0 — this sprint covers remaining polish/tests)*.
- **Status:** ✅ **Attendance half COMPLETE.** ⬜ **Timetable half not started** — the original two-branch plan below was not followed; only `sprint-2-attendance` was executed. Full test suite green after every commit; no new `laravel.log` errors; `route:list` clean throughout.
- **Branch used:** `sprint-2-attendance`, branched from `main` at `bdef442` (post Sprint 0+1 merge — clean, no bundling).
- **Completed deliverables, in commit order** (all on `sprint-2-attendance`, pushed to `origin`, not merged):
  1. `961c765` — **`fix(attendance): add Period fillable, PeriodSeeder`** — `Period` had no `$fillable` (same defect class as the known `AcademicYear` bug), blocking the seeder task itself. New `PeriodSeeder` (7 periods), registered in `DatabaseSeeder`. Note: the full `db:seed` pipeline still can't complete end-to-end — blocked by the pre-existing, out-of-scope `AcademicYear` bug (which runs before `PeriodSeeder`); confirmed `db:seed`'s `Model::unguarded()` wrapper means that bug manifests as a raw SQL "no such column: is_current" error, not the `MassAssignmentException` recorded in the Sprint 1 backlog — see §14 item 1 update.
  2. `cc9c2b2` — **`fix(attendance): fix Filament AttendanceResource create/edit crash`** — `attendance_key` (NOT NULL, unique, no default) was never collected by the form; every create/edit crashed. Added `type`/`period_id` fields and `Attendance::buildAttendanceKey()`, called from `CreateAttendance`/`EditAttendance` page hooks.
  3. `bc5b338` — **`fix(attendance): repair teacher attendance workflow`** — `TeacherAttendance::saveAttendance()` wrote to a nonexistent `student_id` column, never generated `attendance_key`, and called an undefined `notify()`. Rewritten against `Enrollment`, matching `AttendanceController::take()`'s convention; notification fixed to use `Filament\Notifications\Notification`.
  4. `4649068` — **`fix(attendance): restore missing attendance views`** — `dashboard.attendance.reports.student` and `dashboard.attendance.dashboard` didn't exist; both routes 500'd. New views added, reusing existing layout/Chart.js conventions.
  5. `772bcc2` — **`fix(i18n): complete attendance translations`** — 14 missing `attendance.php` keys (12 in `ru`) across all 3 locales; minimal `ar`/`en` `timetable.php` (just the `lesson` key Attendance needed).
  6. `aa5dabb` — **`fix(attendance): harden attendance status validation`** — nested `attendance[*][status]`/`attendance[*][*]` values were never validated against the status enum on either save path; the DB's own CHECK constraint was the only thing stopping bad data, via an uncaught 500. Removed the dead `??` fallback anti-pattern in `take.blade.php`.
  7. `8a12226` — **`fix(attendance): complete attendance UX cleanup`** — added 3 nav links to the real, live dashboard nav (discovered mid-task that `layouts/sidebar.blade.php` is dead code, not used by any Attendance page — see §14 item 8); deleted the confirmed-dead `create.blade.php`; fully localized `AttendanceResource` (first localized Filament resource in the codebase) and `teacher-attendance.blade.php` (was 100% hardcoded Russian).
- **Explicit scope decision — Attendance permission middleware deferred to Sprint 5.** Unlike every Sprint 1 module, Attendance was intentionally left with zero authorization gating this sprint, per direct confirmation. Tracked as backlog item 6 (§14) and added to Sprint 5's task list below.
- **Validation performed on every commit:** focused tests, full `php artisan test` suite (ended at 101 passed / 327 assertions), regression genuinely proven by reverting each fix and confirming the corresponding new test(s) failed before restoring, `route:list` reviewed, `storage/logs/laravel.log` line-count diffed before/after, `git diff` reviewed for scope before each commit.
- **Definition of done:** met for Attendance (all three surfaces — dashboard, `/admin`, `/teacher` — functional and tested); **not met for Timetable** (untouched).

*Original plan (superseded for the Attendance half by what's recorded above; Timetable half still pending as originally planned):*
- **Tasks:** Fix Filament `TimetableResource`'s `name_ru` references; add `Day`/`Period` seeders; add missing `ar`/`en` timetable translations; fix Filament `AttendanceResource`'s incomplete form; rewrite the Teacher-panel `TeacherAttendance` page against the real `enrollment_id`-based schema; verify/add the missing `attendance.reports.student` view.
- **Dependencies:** Sprint 0 merged (Enrollment fix must be live).
- **Expected files:** `app/Filament/Resources/Timetables/**`, `app/Filament/Resources/Attendances/Schemas/AttendanceForm.php`, `app/Filament/Teacher/Pages/TeacherAttendance.php`, new `database/seeders/{Day,Period}Seeder.php`, `resources/lang/{ar,en}/timetable.php`.
- **Required tests:** Extended `TimetableTest.php`; new `AttendanceTest.php` covering dashboard + both Filament surfaces.
- **Branch name:** `sprint-2-timetable` and `sprint-2-attendance` *(two parallel branches within this sprint)*

### Sprint 3 — Exams and report cards
- **Goal:** Build Exams from scratch and make Report Cards render real academic data.
- **Modules:** Exams, Report Cards.
- **Tasks:** New `ExamController` (full CRUD) + views + Filament `ExamResource`; rewrite `ReportCardController` against `StudentGrade`/`Quarter`; resolve the restaurant-report duplication (move that logic fully into `ReportController`, delete it from `ReportCardController`); delete the dangling, broken `GradeEntryController` and the vestigial `Term` migration/seeder (no `Term` model exists; `Quarter` is the concept actually in use).
- **Dependencies:** Sprint 1 ideally merged first (Subjects/Grades stability), though not a hard blocker.
- **Expected files:** New `app/Http/Controllers/Dashboard/ExamController.php`, new `resources/views/dashboard/exams/*`, rewritten `app/Http/Controllers/Dashboard/ReportCardController.php`, `app/Http/Controllers/Dashboard/ReportController.php` (absorbs restaurant logic), new `app/Filament/Resources/Exams/**`, delete `GradeEntryController.php`, new `resources/lang/*/exams.php` + `report_cards.php` (all 3 locales).
- **Shared files requiring merge coordination:** `routes/web.php` if any route names change.
- **Required tests:** New `ExamTest.php`, `ReportCardTest.php`.
- **Definition of done:** Full Exam CRUD works; a report card renders real seeded grade data end-to-end.
- **Branch name:** `sprint-3-exams` and `sprint-3-report-cards`
- **Status:** ⬜ Not started. **Largest single sprint — budget as genuine feature development, not a bug-fix pass.**

### Sprint 4 — Finance hardening
- **Goal:** Resolve the dead Journal/Account subsystem, close authorization gaps, fill translation gaps, remove dead cash duplicates.
- **Modules:** Fees, Invoices, Payments, Cash.
- **Tasks:** Decide and act on Journal/Account (§13); add permission middleware to Fee/Invoice/Debt/Cash controllers; add `debts.php` translations (missing in all 3 locales); delete the 3 confirmed-dead cash controller duplicates.
- **Dependencies:** None blocking — can run fully in parallel with Sprints 1-3.
- **Expected files:** `app/Http/Controllers/Dashboard/InvoiceController.php`, possibly delete `app/Models/{Account,JournalItem}.php` + a drop-migration, `routes/web.php`, new `resources/lang/*/debts.php`, delete 3 controller files.
- **Shared files requiring merge coordination:** `routes/web.php`, `RolesAndPermissionsSeeder.php`.
- **Required tests:** New `FeeTest.php`, `InvoiceTest.php`; regression test that invoice payment recording still works after touching `recordInvoicePayment()`.
- **Definition of done:** No controller references a nonexistent model class; every finance action permission-gated; `debts.php` exists in all 3 locales.
- **Branch name:** `sprint-4-finance-fees-invoices` and `sprint-4-finance-cash`
- **Status:** ⬜ Not started.

### Sprint 5 — Access hardening
- **Goal:** Close the remaining systemic access/dashboard/localization gaps that depend on other sprints having settled.
- **Modules:** Roles and Permissions (remaining item), Dashboard, Localization.
- **Tasks:** Add `canAccessPanel()` to `User` for both Filament panels; reconcile/delete `RolePermissionSeeder`; delete the orphaned `DashboardController` + prune unused dashboard queries + fix/remove the Cash Flow chart; fill every localization file/key gap; add RTL to the guest layout; **add `manage attendance` permission middleware to `AttendanceController`/`TeacherAttendance` (moved from Sprint 2 Task 8 — explicitly deferred, see §14 item 6)**, matching the Sprint 1 pattern (`$this->middleware('permission:manage attendance')` on mutating actions, new permission string in `RolesAndPermissionsSeeder.php`, 403/success test pairs).
- **Dependencies:** Sprints 1-4 substantially settled (permission names, dashboard data shapes should be stable first).
- **Expected files:** `app/Models/User.php`, delete `database/seeders/RolePermissionSeeder.php`, delete `app/Http/Controllers/DashboardController.php`, `resources/views/dashboard/index.blade.php`, multiple `resources/lang/**` files, `resources/views/layouts/guest.blade.php`.
- **Shared files requiring merge coordination:** `RolesAndPermissionsSeeder.php`, translation files (see §8 — low risk, per-file granularity).
- **Required tests:** New `PanelAccessTest.php` (per-role access to both panels); new `DashboardTest.php`; new `LocalizationParityTest.php`.
- **Definition of done:** A non-admin user is denied `/admin` and `/teacher` appropriately (verify every real role before shipping — access-narrowing changes carry real lockout risk); dashboard loads with no dead queries; all lang files at key parity.
- **Branch name:** `sprint-5-access-hardening`, `sprint-5-dashboard-cleanup`, `sprint-5-localization`
- **Status:** ⬜ Not started.

### Sprint 6 — Final QA and production readiness
- **Goal:** Cross-module integration testing, full regression pass, documentation sync, final go/no-go.
- **Modules:** All (integration-level, not module-specific).
- **Tasks:** Fill any remaining test gaps not already covered inline per sprint; run the full cross-module flow (enroll → attend → grade → report card → invoice) end-to-end; update `docs/CHANGELOG.md` and `docs/06_Roadmap.md`.
- **Dependencies:** Sprints 0-5 all merged.
- **Expected files:** `tests/Feature/**` (broad), `docs/CHANGELOG.md`, `docs/06_Roadmap.md`.
- **Shared files requiring merge coordination:** None — this sprint is sequential and solo by design.
- **Required tests:** Full suite, plus new cross-module integration tests.
- **Definition of done:** `php artisan test` fully green including cross-module flows; documentation matches shipped code.
- **Branch name:** `sprint-6-final-qa`
- **Status:** ⬜ Not started.

---

## 6. Parallel execution plan

| Team | Owns | Sprints |
|---|---|---|
| **Team A — Critical path** | Attendance, Timetable, Exams, Report Cards, then Access hardening | Sprint 2 → Sprint 3 → Sprint 5 → Sprint 6 (sequential within the team) |
| **Team B — Foundation** | Students, Teachers, Academic Years, Stages, Grades, Classes, Subjects | Sprint 1 |
| **Team C — Finance** | Fees, Invoices, Payments, Cash | Sprint 4 |
| **Team D — QA** | Cross-cutting test authorship and gatekeeping, every sprint's regression pass | Continuous, formalized in Sprint 6 |

**Who can work simultaneously:** Teams A, B, and C can all start work in parallel **immediately after Sprint 0 is merged into `main`** — their module footprints don't overlap. Team D works continuously alongside all three, reviewing and testing each team's branch before it merges, rather than waiting until the end. Team A's own sprints (2 → 3 → 5) are internally sequential because they build on each other and sit on the critical path; Teams B and C's sprints (1, 4) are **not** on the critical path and can complete anytime during Team A's window without delaying production readiness.

**Files that must not be edited by two teams at the same time:** see §8 for the full rule set. In short: `routes/web.php`, `bootstrap/app.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `resources/views/layouts/dashboard.blade.php` (the live nav — see §8's note on the dead `sidebar.blade.php`), and the Composer/npm dependency files. Everything else (models, individual controllers, individual views, individual Filament resources, individual lang files) is naturally partitioned per module and safe for concurrent work.

---

## 7. Critical path

```
Sprint 0 → Sprint 2 → Sprint 3 → Sprint 5 → Sprint 6
```

Sprints 1 and 4 are **not** on this path — they can complete anytime during the Sprint 2/3 window without delaying production readiness. The critical path represents the core value chain this roadmap exists to make real: **enroll a student → take attendance → record grades → generate a report card → close out the finance workflow around it**, hardened by a final access/QA pass before go-live.

---

## 8. Shared-file ownership rules

| File | Rule |
|---|---|
| `routes/web.php` | Every sprint may add middleware/routes to **its own named route group only** (e.g. `dashboard.students.*` vs `dashboard.timetable.*`). Git merges these cleanly as long as edits don't touch the same lines. Assign **one person as merge/rebase owner** for this file across all active sprints — never let two branches merge it unreviewed. |
| `bootstrap/app.php` | Sprint 0 already added the `role` alias. Any future sprint needing a new middleware alias must coordinate with whoever last touched this file before editing — it's small and rarely touched, but a silent double-edit is easy to miss. |
| Permission seeders (`RolesAndPermissionsSeeder.php`, and the eventual removal of `RolePermissionSeeder.php`) | Additions are append-only (new permission name strings) — low conflict risk, but still funnel all edits through the same single merge owner as `routes/web.php`. Do not delete or rename existing permission strings without checking every `middleware('permission:...')` call site first. |
| Translation files (`resources/lang/**`) | Per-file, per-locale — naturally partitioned. The only coordination needed: if two sprints both add a **new key to the same existing file** (e.g. two teams both touching `dashboard.php`), whoever merges second must check for key collisions before committing. |
| Dashboard nav (`resources/views/layouts/dashboard.blade.php`, **not** `layouts/sidebar.blade.php` — confirmed dead code in Sprint 2, see §14 item 8) | The live, inline nav menu used by all 79 dashboard views. Single-owner file for the duration of active sprint work — a link added by one team and another team's unrelated edit landing in the same file at the same time is an easy source of silent overwrites. |
| `composer.json` | **No sprint may edit this without separate, explicit approval** — see §9. This includes adding new packages (e.g. for Sprint 4's Journal/Account decision, or any future S3 storage work) — always a deliberate, called-out action, never a side effect of a sprint task. |
| `composer.lock` | Never hand-edited. Only ever changes as an automatic side effect of an approved `composer require`/`composer update` — which itself requires separate approval per §9. |
| `package.json` | Same rule as `composer.json` — no sprint adds a new frontend dependency without separate approval. |
| `package-lock.json` | Same rule as `composer.lock` — automatic side effect only, never hand-edited. |

---

## 9. Git workflow

- **One branch per sprint** (or per parallel module-group within a sprint, per §5's suggested branch names) — never more than one active branch per workstream.
- **No direct work on `main`.** Every change lands via a sprint branch, reviewed, then merged.
- **Tests before commit.** Every commit that changes behavior must have its focused test(s) passing locally first.
- **Review before push.** Diff review (scope check, no unrelated files, no leftover debug code) before every push — matches the standard already applied to Sprint 0.
- **Merge only after the full test suite passes** — not just the new sprint's focused tests, the entire `php artisan test` run, to catch cross-sprint regressions.
- **No `composer update`.** Dependency versions stay exactly as locked unless a specific package change is separately requested and approved.
- **No package upgrades** (Composer or npm) unless separately approved — this includes patch/minor bumps, not just majors.

---

## 10. Merge checklist

- [ ] Branch's own focused tests pass
- [ ] Full `php artisan test` suite passes (not just the new tests)
- [ ] `git diff` against `main` reviewed — contains only this sprint's intended files
- [ ] No TODO/FIXME/debug code (`dd()`, `dump()`, `var_dump`, `console.log`) left in the diff
- [ ] No unrelated file touched (check `git status` + full diff, not just the file list)
- [ ] `route:list` reviewed for any routes this sprint added/changed — no collisions, no errors
- [ ] `storage/logs/laravel.log` shows no new errors from the final test run
- [ ] Shared files (§8) reviewed by the designated merge owner if touched
- [ ] Migration reviewed for safety on an empty database (if any migration is included)
- [ ] Commit message is clear and scoped to this sprint only

## 11. QA checklist

- [ ] Every fixed bug has a regression test that fails on the old code and passes on the new code
- [ ] Every new feature (Exams, Report Cards) has full CRUD test coverage, not just a happy-path smoke test
- [ ] Permission/authorization changes tested both ways: authorized user succeeds, unauthorized user is denied
- [ ] Localization changes verified across all 3 locales (`ar`/`en`/`ru`), not just the locale the change was authored in
- [ ] Any Filament resource change manually verified in `/admin` (or `/teacher`), not just via feature test, since Filament's form/table rendering isn't always fully exercised by HTTP-level tests
- [ ] Cross-module flow re-verified after each sprint that touches a link in the enroll → attend → grade → report card → invoice chain

## 12. Deployment-readiness checklist

- [ ] All 7 sprints (0-6) merged into `main`
- [ ] Sprint 0's enrollment migration applied to the real target database (confirmed still pending as of this document)
- [ ] `GradeSeeder`'s stage-mapping assumption confirmed by product owner (§13)
- [ ] Journal/Account subsystem decision made and implemented (§13)
- [ ] `canAccessPanel()` verified against every real role for both Filament panels before shipping — access-narrowing changes carry real lockout risk
- [ ] Full test suite green on the final `main` state
- [ ] Railway deployment configuration (trusted proxies, database sessions, persistent storage path) re-confirmed still correct after all sprint work — this was completed in a separate earlier work stream and should not have been touched by any sprint above
- [ ] `docs/CHANGELOG.md` and `docs/06_Roadmap.md` reflect everything shipped

## 13. Product decisions still awaiting confirmation

1. **`GradeSeeder`'s stage mapping** — Grade 1-6 was mapped to the "Primary" stage as a reasonable inference during Sprint 0, not a specified requirement. Needs explicit confirmation.
2. **Journal/Account subsystem fate** — delete the dead double-entry-bookkeeping tables/models entirely, or invest in completing it as a real feature? Currently dead/unreachable code referencing a nonexistent `JournalEntry` class.
3. **`ClassRoom`/`class_rooms` retirement timing** — ✅ **dependency audit complete (Sprint 1 Task 7)**, confirmed fully orphaned with no unique business meaning. What remains is a pure scheduling/execution decision: run the production pre-flight row-count check (backlog §14), then land the 3-migration removal sequence documented there. Not yet scheduled against a specific sprint.
4. **Report Card business rules/layout** — Sprint 3 needs an actual specification of what a report card should show (grading scale presentation, per-quarter vs. cumulative, etc.) before implementation, not just "make the route work."
5. **`Term` vs `Quarter`** — `Term` is vestigial (no model class, orphaned seeder, a migration meant to link it to Exams is an empty no-op). Confirm it should be removed rather than built out, since `Quarter` is the concept actually in active use everywhere else.
6. **Whether `Admin\UserController::index()`'s view mismatch** (found during Sprint 0 but out of scope there — the view expects `$usersCount` etc., the controller passes `$users`/`$roles`, currently silently shows zero stats via `?? 0`) should be folded into Sprint 1 or Sprint 5.

## 14. Outstanding backlog

Issues discovered during Sprint 1 and Sprint 2 that are real, but fell outside the scope of the task that found them (either the fix would have widened a task's file scope beyond what was asked, or the discovering task was explicitly read-only/audit-only, or the item was an explicit, confirmed scope decision to defer). None of these are fixed yet. None block Sprint 1 or Sprint 2's own completion (§5).

1. **`AcademicYear` — breaks Filament create/edit and the fresh-seed pipeline.** *(Found: Sprint 1 Task 8, verification-only; refined: Sprint 2 Task 1.)* `app/Models/AcademicYear.php` has no `$fillable`/`$guarded` override, so Eloquent's default `$guarded = ['*']` applies. Confirmed two distinct failure modes depending on call path: (a) direct calls (e.g. Filament's `CreateRecord`/`EditRecord` pages, which construct the model directly) throw `MassAssignmentException`; (b) calls routed through Laravel's `SeedCommand`/`$this->seed()` — which wraps execution in `Model::unguarded()` — bypass the guard entirely and instead fail one layer deeper with a raw SQL error, `no such column: is_current` (the real column is `is_active`), when `AcademicYearSeeder` tries to insert it. Either way, **`php artisan db:seed` still cannot complete end-to-end today** — it fails at this seeder (after `RolesAndPermissionsSeeder`/`AdminUserSeeder`/`StageSeeder`/`GradeSeeder`, before Sprint 2's `PeriodSeeder`). **Fix (when scheduled):** add `$fillable = ['name', 'start_date', 'end_date', 'is_active']` to `AcademicYear`; correct `is_current` → `is_active` in `AcademicYearForm.php`, `AcademicYearsTable.php`, and `AcademicYearSeeder.php`.
2. **`StageController` — missing `show()` while the resource route exists.** *(Found: Sprint 1 Task 6, out of scope for a middleware-only task.)* `Route::resource('stages', StageController::class)` registers `dashboard/stages/{stage}` (GET), but `StageController` has no `show()` method — visiting that URL directly would error. Every other Sprint 1 controller (`Grade`, `SchoolClass`, etc.) has a `show()`. **Fix:** add a `show()` method (or redirect to `edit`, matching `GradeController::show()`'s existing pattern).
3. **`StageController::store()` — `order`/`is_active` silently ignored.** *(Found: Sprint 1 Task 6.)* `store()` mass-assigns `order` and `is_active`, but neither is in `Stage::$fillable` (only `name`/`description` are) — they're silently dropped, and every stage is created with the DB defaults (`order` = 1, `is_active` = true) regardless of what the form submits. **Fix:** add `order`/`is_active` to `Stage::$fillable` (with validation), or remove them from the create payload if they're not meant to be user-editable.
4. **`StageController::update()` — uses raw request data.** *(Found: Sprint 1 Task 6.)* `$stage->update($request->all());` passes the entire unvalidated request (including `_token`/`_method`) into `update()` — currently harmless only because `Stage::$fillable` is narrow, but it's a sloppy pattern that would become a real over-posting risk if `$fillable` is widened per item 3 above without also fixing this call site. **Fix:** validate explicitly and pass only the validated array, matching every other Sprint 1 controller's convention.
5. **`class_rooms`/`ClassRoom` retirement — needs a production pre-flight check before the drop.** *(Found: Sprint 1 Task 7, audit-only — see §13 item 3.)* Confirmed orphaned with no unique business meaning; local dev DB shows 0 rows in `class_rooms` and 0 non-null `class_room_id` values in `students`/`enrollments`, but production was not (and could not be, in scope) checked directly. **Before scheduling the drop:** run read-only `SELECT COUNT(*)` checks against production for `class_rooms`, `students.class_room_id`, and `enrollments.class_room_id`; if all zero, proceed with the 3-migration removal sequence already documented under Task 7's findings (drop `students.class_room_id`/`grade_id`/`stage_id` → drop `enrollments.class_room_id` → `dropIfExists('class_rooms')`), then delete `app/Models/ClassRoom.php` and the orphaned `database/seeders/ClassSeeder.php`.
6. **Attendance has zero permission middleware on any surface.** *(Found: Sprint 2 planning; explicitly deferred, not an oversight.)* Unlike every Sprint 1 module, `AttendanceController` and `TeacherAttendance` were left ungated through all of Sprint 2 per direct confirmation — any authenticated user can currently take/edit attendance for any class, on the dashboard, admin, and teacher-panel surfaces alike. **Moved to Sprint 5's task list** (§5) as `manage attendance` permission middleware, matching the Sprint 1 pattern exactly.
7. **Timetable — a second unfixed `orderBy('order')` bug, plus a third broken `name_ru` reference.** *(Found: Sprint 2 planning, out of scope — Sprint 2 only executed the Attendance half.)* `app/Filament/Resources/ClassResource/Pages/TimetableGrid.php` calls `Day::orderBy('order')`/`Period::orderBy('order')` — the exact same nonexistent-column bug Sprint 0 fixed, but only in `TimetableController`; this file was missed and remains broken. Separately, `TimetableResource` references `teacher.name_ru` (`teachers` has `first_name`/`last_name`, no `name_ru`) — a third broken relationship beyond the already-known `day.name_ru`/`period.name_ru` two. **Fix:** scoped to whichever sprint finally executes the Timetable half of Sprint 2's original plan (§5).
8. **`resources/views/layouts/sidebar.blade.php` is dead code.** *(Found: Sprint 2 Task 7.)* A second, parallel navigation partial, only included by `resources/views/layouts/app.blade.php`, itself used by exactly 1 view app-wide. All 79 dashboard views (including every Attendance page) actually extend `layouts.dashboard`, which builds its own separate inline nav using a different translation namespace (`menu.*` vs. this file's `app.*`). Not fixed — deleting or reconciling it wasn't in scope for an Attendance-focused task. **Fix (when scheduled):** confirm `layouts/app.blade.php`'s one remaining consumer, then either retire `sidebar.blade.php` entirely or reconcile the two nav systems — a judgment call for whichever sprint/product owner decides the dashboard layout's long-term shape.

---

## 15. Immediate next action

1. ~~Merge `sprint-0-critical-unblockers` into `main`~~ — ✅ done, via PR #1 (`386722b`).
2. ~~Merge `sprint-1-academic-structure` into `main`~~ — ✅ done, via PR #2 (`bdef442`), which also carried Sprint 0's commits.
3. **Apply the Sprint 0 enrollment migration to the real target database**, if not already done — it's only ever been exercised via the SQLite test suite; confirm this before Sprint 2's Attendance features see real traffic, since Attendance depends on `Enrollment`.
4. **Merge `sprint-2-attendance` into `main`** — Attendance half of Sprint 2 is complete (§5), tested, pushed to `origin`, PR prepared (base `main`, compare `sprint-2-attendance`) but not yet created (no `gh` CLI in the working environment) or merged.
5. **Decide who/when picks up the Timetable half of Sprint 2** — it was never started this sprint (see §5, §4's Timetable row, backlog item 7). The shared `Period` groundwork (`PeriodSeeder`, `Period::$fillable`) is already done and merge-ready for Timetable to build on.
6. Get a decision on item 1 in §13 (the `GradeSeeder` stage mapping) — still unconfirmed by a product owner, still open.
7. Consider scheduling the backlog items in §14 alongside whichever sprint touches their modules next — item 6 (Attendance permissions) is already slotted into Sprint 5; items 1, 2–4, 5, 7, 8 remain unscheduled against a specific sprint.
