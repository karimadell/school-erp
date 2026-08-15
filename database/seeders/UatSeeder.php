<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Academic\Timetable as AcademicTimetable;
use App\Models\BellSchedule;
use App\Models\BellSchedulePeriod;
use App\Models\CashAccount;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PhysicalClassroom;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TeacherSalary;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UatSeeder extends Seeder
{
    private const YEAR = '2026 / 2027';

    public function run(): void
    {
        $isAllowedEnvironment = app()->environment(['staging', 'uat'])
            || (app()->environment('production') && config('uat.enabled') === true);

        if (! $isAllowedEnvironment) {
            throw new RuntimeException('UatSeeder may only run in staging/uat, or in production with UAT_SEED_ENABLED=true.');
        }

        $passwords = $this->validatedPasswords();

        DB::transaction(function () use ($passwords): void {
            $this->call(RolesAndPermissionsSeeder::class);
            $users = $this->users($passwords);
            [$year, $stage, $grade, $class, $classes] = $this->academics();
            [$tuition, $activities] = $this->financeCatalog($year, $grade);
            $account = CashAccount::firstOrCreate(
                ['name' => 'UAT — Основная касса'],
                ['type' => CashAccount::TYPE_CASH, 'balance' => '0.00', 'is_active' => true]
            );

            $students = $this->students($year, $stage, $grade, $class);
            $this->invoices($students, $year, $tuition, $activities, $account, $users['accountant']);
            $this->staffAndTimetable($year, $classes);
        }, 3);
    }

    /** @return array<string, string> */
    private function validatedPasswords(): array
    {
        $passwords = config('uat.passwords', []);

        foreach (['admin', 'accountant', 'cashier', 'reception'] as $role) {
            if (! is_string($passwords[$role] ?? null) || strlen($passwords[$role]) < 12) {
                throw new RuntimeException('Missing or weak UAT_'.strtoupper($role).'_PASSWORD secret (minimum 12 characters).');
            }
        }

        if (count(array_unique($passwords)) !== count($passwords)) {
            throw new RuntimeException('Every UAT account must use a distinct password secret.');
        }

        return $passwords;
    }

    /** @return array<string, User> */
    private function users(array $passwords): array
    {
        $definitions = [
            'admin' => ['Администратор UAT', 'admin.uat@school.test'],
            'accountant' => ['Бухгалтер UAT', 'accountant.uat@school.test'],
            'cashier' => ['Кассир UAT', 'cashier.uat@school.test'],
            'reception' => ['Сотрудник приёмной UAT', 'reception.uat@school.test'],
        ];

        foreach ($definitions as $role => [$name, $email]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make($passwords[$role]), 'is_active' => true]
            );
            $user->syncRoles([$role]);
            $users[$role] = $user;
        }

        return $users;
    }

    /** @return array{AcademicYear, Stage, Grade, SchoolClass, array<int, SchoolClass>} */
    private function academics(): array
    {
        $otherActiveYear = AcademicYear::query()->where('is_active', true)->where('name', '!=', self::YEAR)->first();
        if ($otherActiveYear) {
            throw new RuntimeException('UatSeeder refused to replace the existing active academic year.');
        }

        $year = AcademicYear::firstOrNew(['name' => self::YEAR]);
        $year->fill(['start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true]);
        $year->save();

        $preparatoryStage = Stage::updateOrCreate(
            ['name' => 'UAT — Подготовительная группа'],
            ['description' => 'Только искусственные данные UAT', 'order' => 899, 'is_active' => true]
        );
        $primaryStage = Stage::updateOrCreate(
            ['name' => 'UAT — Начальная школа'],
            ['description' => 'Только искусственные данные UAT', 'order' => 900, 'is_active' => true]
        );
        $combinedSecondaryStage = Stage::updateOrCreate(
            ['name' => 'UAT — Основная и старшая школа'],
            ['description' => 'Только искусственные данные UAT', 'order' => 901, 'is_active' => true]
        );

        $stableCodes = [1 => 'UAT-A', 5 => 'UAT-5A', 9 => 'UAT-9A'];
        $classes = [];
        $grades = [];

        foreach (range(0, 11) as $level) {
            $stage = match (true) {
                $level === 0 => $preparatoryStage,
                $level <= 4 => $primaryStage,
                default => $combinedSecondaryStage,
            };
            $name = "UAT — {$level} класс";
            $grade = Grade::updateOrCreate(['stage_id' => $stage->id, 'name' => $name]);
            $grade->forceFill(['level' => $level])->save();
            $grades[$level] = $grade;

            $classes[$level] = SchoolClass::updateOrCreate(
                ['code' => $stableCodes[$level] ?? "UAT-{$level}A"],
                ['grade_id' => $grade->id, 'name_ar' => "UAT — الصف {$level}", 'name_ru' => $name, 'capacity' => 20, 'is_active' => true]
            );
        }

        return [$year, $primaryStage, $grades[1], $classes[1], $classes];
    }

    /** @return array{Fee, Fee} */
    private function financeCatalog(AcademicYear $year, Grade $grade): array
    {
        $tuition = Fee::updateOrCreate(
            ['name_ru' => 'UAT — Обучение'],
            ['type' => 'yearly', 'category' => Fee::CATEGORY_TUITION, 'payment_period' => Fee::PERIOD_YEARLY, 'amount' => '12000.00', 'is_active' => true]
        );
        $activities = Fee::updateOrCreate(
            ['name_ru' => 'UAT — Школьные мероприятия'],
            ['type' => 'service', 'category' => Fee::CATEGORY_ACTIVITY, 'payment_period' => Fee::PERIOD_ONCE, 'amount' => '1500.00', 'is_active' => true]
        );

        foreach ([[$tuition, '12000.00'], [$activities, '1500.00']] as [$fee, $amount]) {
            FeePrice::updateOrCreate(
                ['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'start_date' => '2026-09-01'],
                ['amount' => $amount, 'currency' => 'EGP', 'end_date' => '2027-05-31', 'change_reason' => 'Искусственный тариф UAT', 'is_active' => true]
            );
        }

        return [$tuition, $activities];
    }

    /** @return array<int, Student> */
    private function students(AcademicYear $year, Stage $stage, Grade $grade, SchoolClass $class): array
    {
        $mode = EnrollmentMode::updateOrCreate(
            ['code' => EnrollmentMode::FULL_TIME],
            ['name_ru' => 'Очная форма обучения', 'short_name_ru' => 'Очная', 'display_order' => 0, 'is_active' => true]
        );
        $lastNames = ['Тестов', 'Примерова', 'Учебный', 'Проверкина', 'Демо'];
        $firstNames = ['Алексей', 'Мария', 'Иван', 'Анна', 'Никита', 'София', 'Максим', 'Елена', 'Павел', 'Ольга', 'Даниил', 'Ирина'];

        foreach ($firstNames as $index => $firstName) {
            $number = $index + 1;
            $student = Student::updateOrCreate(
                ['email' => sprintf('student%02d.uat@example.invalid', $number)],
                [
                    'last_name_ru' => $lastNames[$index % count($lastNames)].sprintf('%02d', $number),
                    'first_name_ru' => $firstName,
                    'patronymic_ru' => null,
                    'class_id' => $class->id,
                    'birth_date' => sprintf('2019-%02d-%02d', ($index % 9) + 1, ($index % 20) + 1),
                    'gender' => $index % 2 === 0 ? 'male' : 'female',
                    'phone' => null,
                    'address' => null,
                    'photo' => null,
                    'documents' => [],
                    'nationality' => 'UAT',
                    'status' => Student::STATUS_ACTIVE,
                ]
            );
            Enrollment::updateOrCreate(
                ['student_id' => $student->id, 'academic_year_id' => $year->id],
                ['enrollment_mode_id' => $mode->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id, 'academic_year' => $year->name, 'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true, 'notes' => 'Искусственная запись UAT']
            );
            $students[] = $student;
        }

        return $students;
    }

    private function invoices(array $students, AcademicYear $year, Fee $tuition, Fee $activities, CashAccount $account, User $actor): void
    {
        $states = [['unpaid', '12000.00', null], ['partial', '12000.00', '4000.00'], ['paid', '1500.00', '1500.00']];

        foreach ($states as $index => [$label, $total, $payment]) {
            $fee = $label === 'paid' ? $activities : $tuition;
            $number = 'UAT-INV-2026-'.strtoupper($label);
            $invoice = Invoice::firstOrCreate(
                ['invoice_number' => $number],
                ['student_id' => $students[$index]->id, 'academic_year_id' => $year->id, 'customer_name' => $students[$index]->full_name, 'currency' => 'EGP', 'subtotal_amount' => $total, 'total_amount' => $total, 'discount_amount' => '0.00', 'paid_amount' => '0.00', 'remaining_amount' => $total, 'status' => Invoice::STATUS_UNPAID, 'due_date' => '2027-05-31', 'created_by' => $actor->id]
            );
            InvoiceItem::firstOrCreate(
                ['invoice_id' => $invoice->id, 'fee_id' => $fee->id],
                ['description' => $fee->name_ru, 'unit_price' => $total, 'quantity' => 1, 'amount' => $total, 'paid_amount' => '0.00', 'remaining_amount' => $total, 'metadata' => ['source' => 'uat']]
            );

            if ($payment && ! $invoice->payments()->exists()) {
                app(InvoicePaymentService::class)->record(
                    $invoice->id,
                    $account->id,
                    $payment,
                    'card',
                    $label === 'partial' ? '00000000-0000-4000-8000-000000000001' : '00000000-0000-4000-8000-000000000002',
                    $actor,
                    notes: 'Искусственный платёж UAT'
                );
            }
        }
    }

    /** @param array<int, SchoolClass> $schoolClasses */
    private function staffAndTimetable(AcademicYear $year, array $schoolClasses): void
    {
        $classes = [
            'first' => $schoolClasses[1],
            'fifth' => $schoolClasses[5],
            'ninth' => $schoolClasses[9],
        ];

        $definitions = [
            'RUS' => 'Русский язык', 'MATH' => 'Математика', 'LITREAD' => 'Литературное чтение',
            'EN' => 'Английский язык', 'PE' => 'Физкультура', 'WORLD' => 'Окружающий мир',
            'HISTORY' => 'История', 'GEOGRAPHY' => 'География', 'LITERATURE' => 'Литература',
            'ALGEBRA' => 'Алгебра', 'GEOMETRY' => 'Геометрия', 'PHYSICS' => 'Физика',
            'CHEMISTRY' => 'Химия', 'BIOLOGY' => 'Биология', 'IT' => 'Информатика',
            'SAFETY' => 'ОБЖ', 'HOMEROOM' => 'Классный час', 'TECH' => 'Технология',
            'EXTRAREAD' => 'Внеклассное чтение',
        ];
        $subjects = [];
        foreach ($definitions as $code => $name) {
            $subjects[$code] = $this->uatSubject($code, $name);
        }

        $teachers = [
            'primary' => Teacher::updateOrCreate(
                ['email' => 'teacher.primary.uat@example.invalid'],
                ['first_name' => 'Тестовый учитель', 'patronymic' => 'начальной школы', 'last_name' => 'УАТ-Начальная', 'specialization' => 'UAT — начальная школа', 'hire_date' => '2026-07-01', 'is_active' => true],
            ),
            'senior' => Teacher::updateOrCreate(
                ['email' => 'teacher.senior.uat@example.invalid'],
                ['first_name' => 'Тестовый учитель', 'patronymic' => 'старшей школы', 'last_name' => 'УАТ-Старшая', 'specialization' => 'UAT — основная и старшая школа', 'hire_date' => '2026-07-01', 'is_active' => true],
            ),
        ];

        $sourceLessons = [
            'first' => [
                7 => ['RUS', 'EN', 'WORLD', 'LITREAD', 'EXTRAREAD', 'EXTRAREAD'],
                1 => ['RUS', 'TECH', 'PE', 'MATH', 'LITREAD', 'EXTRAREAD'],
            ],
            'fifth' => [
                7 => ['HOMEROOM', 'RUS', 'MATH', 'HISTORY', 'GEOGRAPHY', 'PE'],
                1 => ['RUS', 'RUS', 'MATH', 'EN', 'MATH', 'RUS'],
            ],
        ];

        $assignments = [];
        foreach ($sourceLessons as $classKey => $days) {
            $teacher = $classKey === 'first' ? $teachers['primary'] : $teachers['senior'];
            $subjectCodes = collect($days)->flatten()->unique()->values();
            $teacher->subjects()->syncWithoutDetaching($subjectCodes->map(fn (string $code) => $subjects[$code]->id));

            foreach ($subjectCodes as $code) {
                $curriculum = Curriculum::updateOrCreate(
                    ['academic_year_id' => $year->id, 'grade_id' => $classes[$classKey]->grade_id, 'subject_id' => $subjects[$code]->id],
                    ['weekly_hours' => max(1, collect($days)->flatten()->filter(fn ($value) => $value === $code)->count()), 'type' => Curriculum::TYPE_MANDATORY, 'assessment_type' => Curriculum::ASSESSMENT_UNGRADED, 'is_active' => true, 'notes' => 'Искусственный учебный план UAT'],
                );
                $assignments[$classKey][$code] = TeacherAssignment::updateOrCreate([
                    'teacher_id' => $teacher->id,
                    'class_id' => $classes[$classKey]->id,
                    'subject_id' => $subjects[$code]->id,
                    'academic_year_id' => $year->id,
                ]);
            }
        }

        $salary = TeacherSalary::where('teacher_id', $teachers['primary']->id)
            ->whereDate('salary_month', '2026-07-01')
            ->firstOrNew();
        $salary->fill([
            'teacher_id' => $teachers['primary']->id,
            'salary_month' => '2026-07-01',
            'base_salary' => '18000.00',
            'bonus' => '1200.00',
            'deductions' => '300.00',
        ])->save();

        $schedule = BellSchedule::updateOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'UAT — Расписание звонков 09:00'],
            ['shift' => 1, 'is_default' => true, 'is_active' => true, 'notes' => 'Время уроков из предоставленных таблиц UAT'],
        );
        $times = [
            1 => ['09:00', '09:40', 10], 2 => ['09:50', '10:30', 5], 3 => ['10:35', '11:15', 5],
            4 => ['11:20', '12:00', 15], 5 => ['12:15', '12:55', 15], 6 => ['13:10', '13:50', 0],
        ];
        foreach ($times as $number => [$start, $end, $break]) {
            $periods[$number] = BellSchedulePeriod::updateOrCreate(
                ['bell_schedule_id' => $schedule->id, 'period_number' => $number],
                ['label' => "{$number}-й урок", 'starts_at' => $start, 'ends_at' => $end, 'break_after_minutes' => $break, 'is_active' => true],
            );
        }

        foreach (['first' => 'UAT-1A', 'fifth' => 'UAT-5A'] as $classKey => $code) {
            $rooms[$classKey] = PhysicalClassroom::updateOrCreate(
                ['academic_year_id' => $year->id, 'code' => $code],
                ['building' => 'UAT', 'floor' => $classKey === 'first' ? '1' : '2', 'name' => "UAT — кабинет {$classes[$classKey]->name_ru}", 'capacity' => 20, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM, 'is_active' => true, 'notes' => 'Искусственный кабинет UAT'],
            );
        }

        $version = TimetableVersion::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'UAT — Расписание по образцу 2025/26'],
            ['status' => TimetableVersion::STATUS_DRAFT, 'effective_from' => $year->start_date, 'effective_to' => $year->end_date, 'notes' => 'Репрезентативная выборка из предоставленных таблиц без персональных данных'],
        );
        $header = AcademicTimetable::firstOrCreate(
            ['timetable_version_id' => $version->id, 'name' => 'UAT — Начальная и старшая школа'],
            ['notes' => 'Воскресенье и понедельник, 1 и 5 классы'],
        );

        foreach ($sourceLessons as $classKey => $days) {
            foreach ($days as $weekday => $lessonCodes) {
                foreach ($lessonCodes as $offset => $code) {
                    $period = $periods[$offset + 1];
                    TimetableEntry::updateOrCreate(
                        ['timetable_version_id' => $version->id, 'class_id' => $classes[$classKey]->id, 'weekday' => $weekday, 'bell_schedule_period_id' => $period->id],
                        ['academic_timetable_id' => $header->id, 'bell_schedule_id' => $schedule->id, 'classroom_id' => $rooms[$classKey]->id, 'teacher_assignment_id' => $assignments[$classKey][$code]->id, 'curriculum_id' => Curriculum::where('academic_year_id', $year->id)->where('grade_id', $classes[$classKey]->grade_id)->where('subject_id', $subjects[$code]->id)->value('id'), 'subject_id' => $subjects[$code]->id],
                    );
                }
            }
        }
    }

    private function uatSubject(string $code, string $name): Subject
    {
        if ($subject = Subject::where('code', "UAT-{$code}")->first()) {
            $subject->update([
                'name_ar' => "UAT — {$name}",
                'name_ru' => $name,
                'description' => 'Искусственный предмет UAT',
                'is_active' => true,
            ]);

            return $subject;
        }

        $subject = Subject::all()->first(
            fn (Subject $candidate) => mb_strtolower(trim((string) $candidate->name_ru)) === mb_strtolower($name),
        );

        return $subject ?? Subject::create([
            'code' => "UAT-{$code}",
            'name_ar' => "UAT — {$name}",
            'name_ru' => $name,
            'description' => 'Искусственный предмет UAT',
            'is_active' => true,
        ]);
    }
}
