<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
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
            [$year, $stage, $grade, $class] = $this->academics();
            [$tuition, $activities] = $this->financeCatalog($year, $grade);
            $account = CashAccount::firstOrCreate(
                ['name' => 'UAT — Основная касса'],
                ['type' => CashAccount::TYPE_CASH, 'balance' => '0.00', 'is_active' => true]
            );

            $students = $this->students($year, $stage, $grade, $class);
            $this->invoices($students, $year, $tuition, $activities, $account, $users['accountant']);
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

    /** @return array{AcademicYear, Stage, Grade, SchoolClass} */
    private function academics(): array
    {
        $otherActiveYear = AcademicYear::query()->where('is_active', true)->where('name', '!=', self::YEAR)->first();
        if ($otherActiveYear) {
            throw new RuntimeException('UatSeeder refused to replace the existing active academic year.');
        }

        $year = AcademicYear::firstOrNew(['name' => self::YEAR]);
        $year->fill(['start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true]);
        $year->save();

        $stage = Stage::updateOrCreate(
            ['name' => 'UAT — Начальная школа'],
            ['description' => 'Только искусственные данные UAT', 'order' => 900, 'is_active' => true]
        );
        $grade = Grade::updateOrCreate(['stage_id' => $stage->id, 'name' => 'UAT — 1 класс']);
        $grade->forceFill(['level' => 1])->save();
        $class = SchoolClass::updateOrCreate(
            ['grade_id' => $grade->id, 'code' => 'UAT-A'],
            ['name_ar' => 'فصل اختبار القبول', 'name_ru' => 'UAT — 1А', 'capacity' => 20, 'is_active' => true]
        );

        foreach ([['UAT-RUS', 'Русский язык'], ['UAT-MATH', 'Математика']] as [$code, $name]) {
            Subject::updateOrCreate(['code' => $code], ['name_ar' => "UAT {$code}", 'name_ru' => "UAT — {$name}", 'is_active' => true]);
        }

        return [$year, $stage, $grade, $class];
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
}
