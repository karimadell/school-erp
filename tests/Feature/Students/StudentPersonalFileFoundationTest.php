<?php

namespace Tests\Feature\Students;

use App\Models\DocumentType;
use App\Models\Student;
use App\Models\StudentEducationalNeed;
use App\Models\StudentFile;
use App\Models\StudentRepresentative;
use App\Models\User;
use App\Services\Students\StudentProfileCompletionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class StudentPersonalFileFoundationTest extends StudentCompletionTestCase
{
    public function test_additive_schema_and_document_type_seed_preserve_legacy_foundation(): void
    {
        foreach (['first_name', 'last_name', 'patronymic', 'birth_place', 'citizenship_code', 'residential_address', 'registration_address', 'snils', 'inn', 'registration_status'] as $column) {
            $this->assertTrue(Schema::hasColumn('students', $column));
        }
        foreach (array_keys(StudentFile::TYPES) as $code) {
            $this->assertDatabaseHas('document_types', ['code' => $code]);
        }
        $this->assertSame(Student::STATUS_PRE_REGISTERED, $this->student->status);
        $this->student->update(['documents' => ['legacy' => 'kept']]);
        $this->assertSame(['legacy' => 'kept'], $this->student->fresh()->documents);
    }

    public function test_multiple_representatives_and_normalized_first_legacy_fallback_work(): void
    {
        $this->student->update(['documents' => ['father' => ['name' => 'Иванов Сергей', 'phone' => '+201']]]);
        $legacy = $this->student->fresh()->representativeData('father');
        $this->assertSame('Иванов Сергей', $legacy['name']);
        $father = $this->student->representatives()->create(['relationship_type' => 'father', 'full_name' => 'Нормализованный отец', 'is_primary_contact' => true]);
        $this->student->representatives()->create(['relationship_type' => 'guardian', 'full_name' => 'Опекун']);
        $this->student->representatives()->create(['relationship_type' => 'guardian', 'full_name' => 'Второй опекун']);
        $this->assertSame('Нормализованный отец', $this->student->fresh()->representativeData('father')['full_name']);
        $this->assertTrue($father->is_primary_contact);
        $this->assertCount(3, $this->student->representatives);
    }

    public function test_emergency_contacts_are_prioritized_and_enrollment_context_does_not_change_placement(): void
    {
        $this->student->emergencyContacts()->create(['full_name' => 'Второй', 'phone' => '+202', 'priority' => 2]);
        $this->student->emergencyContacts()->create(['full_name' => 'Первый', 'phone' => '+201', 'priority' => 1]);
        $this->assertSame('Первый', $this->student->fresh()->emergencyContactData()['full_name']);
        $enrollment = $this->student->currentEnrollment;
        $placement = $enrollment->only(['academic_year_id', 'stage_id', 'grade_id', 'class_id', 'enrollment_mode_id']);
        $enrollment->update(['admission_context' => 'transfer', 'previous_school_name' => 'School 1', 'previous_grade' => '2']);
        $this->assertSame($placement, $enrollment->fresh()->only(array_keys($placement)));
    }

    public function test_educational_needs_keep_unknown_no_and_yes_distinct_and_teacher_access_is_not_broadened(): void
    {
        $need = StudentEducationalNeed::create(['student_id' => $this->student->id, 'has_ovz' => null, 'has_disability' => false, 'requires_special_conditions' => true]);
        $need = $need->fresh();
        $this->assertNull($need->has_ovz);
        $this->assertFalse($need->has_disability);
        $this->assertTrue($need->requires_special_conditions);
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        $this->actingAs($teacher)->get(route('dashboard.students.complete-registration.edit', $this->student))->assertRedirect('/login');
    }

    public function test_structured_file_associations_are_same_student_scoped(): void
    {
        Storage::fake('local');
        $type = DocumentType::where('code', 'passport')->firstOrFail();
        $representative = $this->student->representatives()->create(['relationship_type' => 'guardian', 'full_name' => 'Guardian']);
        $enrollment = $this->student->currentEnrollment;
        $this->actingAs($this->manager)->post(route('dashboard.students.documents.store', $this->student), ['document_type_id' => $type->id, 'student_representative_id' => $representative->id, 'enrollment_id' => $enrollment->id, 'series' => 'AB', 'document_number' => '123', 'files' => [UploadedFile::fake()->create('p.pdf', 10, 'application/pdf')]])->assertRedirect();
        $this->assertDatabaseHas('student_files', ['student_id' => $this->student->id, 'type' => 'passport', 'document_type_id' => $type->id, 'student_representative_id' => $representative->id, 'enrollment_id' => $enrollment->id, 'series' => 'AB', 'document_number' => '123']);
        $other = Student::create(['name' => 'Other']);
        $otherRepresentative = StudentRepresentative::create(['student_id' => $other->id, 'relationship_type' => 'guardian', 'full_name' => 'Other guardian']);
        $this->actingAs($this->manager)->post(route('dashboard.students.documents.store', $this->student), ['document_type_id' => $type->id, 'student_representative_id' => $otherRepresentative->id, 'files' => [UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('student_representative_id');
    }

    public function test_readiness_is_canonical_finance_independent_and_optional_identifiers_do_not_count(): void
    {
        $service = app(StudentProfileCompletionService::class);
        $before = $service->calculate($this->student);
        $this->assertSame($before['student_data_percentage'], $this->student->profile_completion_percentage);
        $this->student->update(['snils' => null, 'inn' => null]);
        $this->assertSame($before['student_data_percentage'], $service->calculate($this->student->fresh())['student_data_percentage']);
        $this->assertFalse($before['can_submit_for_review']);
        $this->assertNull($before['finance_present']);
    }

    public function test_registration_page_renders_localized_rtl_foundation(): void
    {
        app()->setLocale('ar');
        $this->actingAs($this->manager)->get(route('dashboard.students.complete-registration.edit', $this->student))->assertOk()->assertSee('dir="rtl"', false)->assertSee('الاحتياجات التعليمية الخاصة');
        app()->setLocale('en');
        $this->actingAs($this->manager)->get(route('dashboard.students.complete-registration.edit', $this->student))->assertOk()->assertSee('Personal file readiness');
    }

    public function test_phase_one_readiness_and_document_validation_are_localized(): void
    {
        $expected = [
            'ru' => ['Фото ученика', 'Выберите хотя бы один файл.'],
            'en' => ['Student photograph', 'Select at least one file.'],
            'ar' => ['صورة الطالب', 'اختر ملفاً واحداً على الأقل.'],
        ];

        foreach ($expected as $locale => [$documentLabel, $validationMessage]) {
            app()->setLocale($locale);
            $readiness = app(StudentProfileCompletionService::class)->calculate($this->student->fresh());
            $this->assertContains($documentLabel, $readiness['missing_document_items']);

            $this->actingAs($this->manager)
                ->post(route('dashboard.students.documents.store', $this->student), ['document_type_id' => DocumentType::where('code', 'passport')->value('id')])
                ->assertSessionHasErrors('files');
            $this->assertSame($validationMessage, session('errors')->first('files'));

            if ($locale !== 'ru') {
                $this->assertNotContains('Фото ученика', $readiness['missing_document_items']);
                $this->assertNotSame('Выберите хотя бы один файл.', session('errors')->first('files'));
            }
        }
    }

    public function test_registration_submission_and_completion_responses_are_localized(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('photo.jpg', 'x');
        $this->student->update(array_merge($this->profilePayload(), ['photo' => 'photo.jpg', 'documents' => ['father' => ['phone' => '+201011111111']]]));
        foreach (['birth_certificate', 'passport', 'previous_school', 'medical'] as $type) {
            StudentFile::create(['student_id' => $this->student->id, 'title' => $type, 'file_name' => $type.'.pdf', 'file_path' => $type.'.pdf', 'file_type' => 'application/pdf', 'file_size' => 1, 'category' => 'other', 'type' => $type]);
        }

        $expected = [
            'ru' => ['Личное дело отправлено на проверку.', 'Регистрация ученика завершена.'],
            'en' => ['The personal file was submitted for review.', 'Student registration completed.'],
            'ar' => ['تم إرسال الملف الشخصي للمراجعة.', 'تم استكمال تسجيل الطالب.'],
        ];

        foreach ($expected as $locale => [$submitted, $completed]) {
            app()->setLocale($locale);
            $this->student->update(['status' => Student::STATUS_PRE_REGISTERED, 'registration_status' => 'ready_for_review']);
            $this->actingAs($this->manager)->post(route('dashboard.students.registration-review.submit', $this->student))->assertSessionHas('success', $submitted);
            $this->actingAs($this->manager)->post(route('dashboard.students.registration-review.complete', $this->student))->assertSessionHas('success', $completed);

            if ($locale !== 'ru') {
                $this->assertNotSame('Личное дело отправлено на проверку.', $submitted);
                $this->assertNotSame('Регистрация ученика завершена.', $completed);
            }
        }
    }
}
