# School ERP — Project Command Center

> Living reference document. Built from the Phase 2 codebase inspection, the execution master plan, and the Sprint 0 implementation already completed in this project's working history. Update this file as sprints land — do not let it drift from reality.
>
> **Uncertainty marker used throughout:** ⚠️ *unconfirmed* — means inferred/recommended, not verified against a product decision or a real (non-SQLite-test) database.

---

## 1. Project goal

Bring this Laravel 12 School ERP (students, teachers, academic structure, timetable, attendance, exams, finance, and a bilingual/trilingual Arabic-Russian-English admin experience, split across a custom Blade dashboard and a parallel Filament admin panel) from its current partially-broken state to a genuinely production-ready system — without a rewrite, by systematically closing the gaps found during inspection.

## 2. Current production-readiness status

**Not production-ready.** Sprint 0 removed the most severe, universally-blocking defects (see §3), but multiple modules are still either broken in specific surfaces (Filament forms, Teacher panel pages) or entirely unimplemented (Exams, Report Cards). Railway deployment preparation (trusted proxies, database-backed sessions) was completed in an earlier work stream and is separate from the sprint numbering in this document.

## 3. Current Git status

| Item | Value |
|---|---|
| `main` branch commit (`origin/main`) | `5bae4b4` — "feat(deployment): prepare database-backed sessions" |
| Sprint 0 branch | `sprint-0-critical-unblockers` |
| Sprint 0 commit | `91124e2` — "fix(core): complete sprint 0 critical unblockers" |
| Pushed? | ✅ Yes — pushed to `origin/sprint-0-critical-unblockers` |
| Merged into `main`? | ❌ **Not merged.** `git merge-base --is-ancestor` confirms `91124e2` is not an ancestor of `origin/main`. |

---

## 4. Module status table

Legend for **Assigned sprint**: matches §5 below. **Suggested branch name** follows `sprint-N-<module-group>` so parallel teams never share a branch for unrelated modules within the same sprint.

| Module | Current status | Missing work | Priority | Dependency | Assigned sprint | Suggested branch | Tests required |
|---|---|---|---|---|---|---|---|
| **Students** | 3 controllers exist; only `Dashboard\StudentController` is routed (full CRUD, photo/document upload work). `StudentsController` (top-level) and `Dashboard\StudentsController` are confirmed dead code, zero references. | Delete 2 dead controllers; add permission middleware (none exists today). | Medium | Stages/Grades/Classes (enrollment) | Sprint 1 | `sprint-1-people-foundation` | New `StudentTest.php` (CRUD + authorization) |
| **Teachers** | `Dashboard\TeacherController` — single, wired, CRUD + document upload/delete + PDF/Excel export all work. | Add permission middleware (none exists). | Medium | Subjects (pivot) | Sprint 1 | `sprint-1-people-foundation` | New `TeacherTest.php` |
| **Academic Years** | No dashboard-side controller or route at all — intentional (dead sidebar link removed in an earlier session). Filament `AcademicYearResource` is the sole, fully-built management path. | None required unless a native dashboard screen is later requested (out of current scope). | Low | — | Sprint 1 *(verification only)* | `sprint-1-academic-structure` | None currently planned |
| **Stages** | `Stage` model/`StageController` exist. `StageSeeder` was **fixed in Sprint 0** (was writing to a nonexistent `title` column instead of `name`, which halted the entire seeding pipeline before `GradeSeeder` ever ran). Controller-level CRUD behavior beyond the seeder was **not deeply audited** ⚠️ *unconfirmed*. | Full CRUD/authorization audit; permission middleware. | Medium | — | Sprint 1 | `sprint-1-academic-structure` | New `StageTest.php` |
| **Grades** *(class-year, e.g. "Grade 1")* | `GradeController` works correctly today (only ever writes `stage_id`+`name`, matching the real schema). `GradeSeeder` was **fixed in Sprint 0** (now supplies `stage_id`, mapped to the "Primary" stage ⚠️ *unconfirmed mapping — a judgment call, not a specified requirement*). | `Grade` model's `order`/`is_active` fillable/cast fields reference columns that **do not exist** — dead code, should be removed or the columns added. A duplicate, dead `create_grades_table` migration (an abandoned "Russian numeric score" schema) exists and never applies — should be deleted for clarity. | Medium | Stages | Sprint 1 | `sprint-1-academic-structure` | New `GradeTest.php` |
| **Classes** | `classes` table + `SchoolClass` model + `ClassController` — the live, working implementation. **Newly found during Sprint 0 test-writing:** `classes.name_ar` is NOT NULL in the database but is **not in `SchoolClass`'s `$fillable`** — any code path that doesn't bypass mass assignment will violate this constraint. The legacy `class_rooms` table / `ClassRoom` model is a confirmed orphaned duplicate of this table (its own seeder is never called). | Fix the `name_ar` fillable gap; retire `class_rooms`/`ClassRoom` only after a full dependency audit (nothing currently in Sprint 0 touched this). | High *(the `name_ar` gap is a live latent bug)* | — | Sprint 1 | `sprint-1-academic-structure` | New `SchoolClassTest.php` |
| **Subjects** | `Dashboard\SubjectController`, resource-routed, CRUD works. Later migrations added code/description/color/weekly_lessons fields. | Add permission middleware (none exists). | Low | — | Sprint 1 | `sprint-1-people-foundation` | New `SubjectTest.php` |
| **Timetable** | `Dashboard\TimetableController` — full CRUD + conflict detection (class/teacher/room clash) + PDF export, generally solid. `show()`'s `Day::orderBy('order')` bug (nonexistent column) was **fixed in Sprint 0**. | Filament `TimetableResource` still references a nonexistent `name_ru` column on `days`/`periods` — will error on create/edit in `/admin`. No `Day`/`Period` seeders exist (empty dropdowns on fresh installs). `timetable.php` lang file missing in `ar`/`en` (only `ru` exists). | High | SchoolClass, Subject, Teacher | Sprint 2 | `sprint-2-timetable` | Extend `tests/Feature/TimetableTest.php` (Filament + seeder coverage) |
| **Attendance** | `Dashboard\AttendanceController` — take/store/reports/dashboard chart all implemented, keyed via `Enrollment` (not `Student` directly). The upstream Enrollment blocker (`class_room_id`/`academic_year_id` NOT NULL with no default, never populated) was **fixed in Sprint 0** via a new nullable-columns migration — this was silently blocking all new enrollments and, by extension, all attendance for newly-enrolled students. | Filament `AttendanceResource`'s form is missing required fields (`type`, `period_id`, `attendance_key` — a NOT NULL unique column) — creating attendance via `/admin` still fails. The Teacher-panel `TeacherAttendance` page uses a nonexistent `student_id` column — completely non-functional, needs a rewrite against the real `enrollment_id`-based schema. `dashboard.attendance.reports.student` view's existence was **not directly verified** ⚠️ *unconfirmed*. | High | Enrollment (now fixed) | Sprint 2 | `sprint-2-attendance` | Extend `tests/Feature/EnrollmentTest.php`; new `AttendanceTest.php` |
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
- **Tasks:** Delete 2 dead Student controllers; fix `SchoolClass.name_ar` fillable gap; delete the dead duplicate `grades`-migration and `Grade` model's dangling `order`/`is_active`; audit and retire `class_rooms`/`ClassRoom` (only after confirming zero remaining dependents); add permission middleware across Students/Teachers/Subjects/Stages/Grades/Classes.
- **Dependencies:** Sprint 0 merged.
- **Expected files:** `app/Models/{Grade,SchoolClass}.php`, delete 2 controller files, a new drop-migration for `class_rooms` *(only after dependency audit)*, `routes/web.php` (permission middleware on these groups), `database/seeders/RolesAndPermissionsSeeder.php` (additive new permission names).
- **Shared files requiring merge coordination:** `routes/web.php`, `database/seeders/RolesAndPermissionsSeeder.php` — see §8.
- **Required tests:** New `StudentTest.php`, `TeacherTest.php`, `SubjectTest.php`, `StageTest.php`, `GradeTest.php`, `SchoolClassTest.php`.
- **Definition of done:** Full suite green; every listed controller CRUD confirmed still working post-cleanup; permission-denial tests pass for each.
- **Branch name:** `sprint-1-people-foundation` and `sprint-1-academic-structure` *(two branches — see §6, these can run as separate parallel workstreams within the same sprint)*
- **Status:** ⬜ Not started.

### Sprint 2 — Attendance and enrollment
- **Goal:** Make Timetable and Attendance fully functional across all three surfaces (dashboard, Filament admin, Filament teacher panel).
- **Modules:** Timetable, Attendance *(Enrollment's schema fix already landed in Sprint 0 — this sprint covers remaining polish/tests)*.
- **Tasks:** Fix Filament `TimetableResource`'s `name_ru` references; add `Day`/`Period` seeders; add missing `ar`/`en` timetable translations; fix Filament `AttendanceResource`'s incomplete form; rewrite the Teacher-panel `TeacherAttendance` page against the real `enrollment_id`-based schema; verify/add the missing `attendance.reports.student` view.
- **Dependencies:** Sprint 0 merged (Enrollment fix must be live).
- **Expected files:** `app/Filament/Resources/Timetables/**`, `app/Filament/Resources/Attendances/Schemas/AttendanceForm.php`, `app/Filament/Teacher/Pages/TeacherAttendance.php`, new `database/seeders/{Day,Period}Seeder.php`, `resources/lang/{ar,en}/timetable.php`.
- **Shared files requiring merge coordination:** None expected beyond `DatabaseSeeder.php` if new seeders are registered there.
- **Required tests:** Extended `TimetableTest.php`; new `AttendanceTest.php` covering dashboard + both Filament surfaces.
- **Definition of done:** All three Timetable/Attendance surfaces (dashboard, `/admin`, `/teacher`) functional and tested.
- **Branch name:** `sprint-2-timetable` and `sprint-2-attendance` *(two parallel branches within this sprint)*
- **Status:** ⬜ Not started.

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
- **Tasks:** Add `canAccessPanel()` to `User` for both Filament panels; reconcile/delete `RolePermissionSeeder`; delete the orphaned `DashboardController` + prune unused dashboard queries + fix/remove the Cash Flow chart; fill every localization file/key gap; add RTL to the guest layout.
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

**Files that must not be edited by two teams at the same time:** see §8 for the full rule set. In short: `routes/web.php`, `bootstrap/app.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `resources/views/layouts/sidebar.blade.php`, and the Composer/npm dependency files. Everything else (models, individual controllers, individual views, individual Filament resources, individual lang files) is naturally partitioned per module and safe for concurrent work.

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
| Dashboard sidebar (`resources/views/layouts/sidebar.blade.php`) | Single file, edited historically only when adding/removing a nav link. Treat as a single-owner file for the duration of active sprint work — a link added by one team and another team's unrelated edit landing in the same file at the same time is an easy source of silent overwrites. |
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
3. **`ClassRoom`/`class_rooms` retirement timing** — confirmed orphaned, but the exact safe removal sequence (dependency audit first) hasn't been scheduled against a specific sprint.
4. **Report Card business rules/layout** — Sprint 3 needs an actual specification of what a report card should show (grading scale presentation, per-quarter vs. cumulative, etc.) before implementation, not just "make the route work."
5. **`Term` vs `Quarter`** — `Term` is vestigial (no model class, orphaned seeder, a migration meant to link it to Exams is an empty no-op). Confirm it should be removed rather than built out, since `Quarter` is the concept actually in active use everywhere else.
6. **Whether `Admin\UserController::index()`'s view mismatch** (found during Sprint 0 but out of scope there — the view expects `$usersCount` etc., the controller passes `$users`/`$roles`, currently silently shows zero stats via `?? 0`) should be folded into Sprint 1 or Sprint 5.

## 14. Immediate next action

1. **Merge `sprint-0-critical-unblockers` into `main`** — it's tested, reviewed, and pushed; nothing is blocking this merge today.
2. **Apply the new enrollment migration to the real target database** once merged — it's only been exercised via the SQLite test suite so far.
3. **Get a decision on item 1 in §13** (the `GradeSeeder` stage mapping) before Sprint 1 starts, since Sprint 1 will build directly on top of it.
4. Once merged, **kick off Teams A, B, and C in parallel** (Sprints 2, 1, and 4 respectively) — all three are unblocked as soon as Sprint 0 lands on `main`.
