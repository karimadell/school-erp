<?php
namespace Tests\Feature\Students;
use App\Models\Student;
use App\Services\Students\StudentProfileCompletionService;
class StudentProfileCompletionTest extends StudentCompletionTestCase {
 public function test_page_is_russian_and_update_preserves_finance_separation():void { $this->actingAs($this->manager)->get(route('dashboard.students.complete-registration.edit',$this->student))->assertOk()->assertSee('Завершение регистрации ученика')->assertSee('Финансы (только просмотр)'); $this->actingAs($this->manager)->put(route('dashboard.students.complete-registration.update',$this->student),$this->profilePayload())->assertRedirect(); $this->assertSame(Student::STATUS_DOCUMENTS_REQUIRED,$this->student->fresh()->status); }
 public function test_completion_is_centralized_and_debt_is_not_a_blocker():void { $result=app(StudentProfileCompletionService::class)->calculate($this->student); $this->assertFalse($result['can_submit_for_review']); $this->assertSame(5,$result['documents_required_count']); $this->assertContains('Фото ученика',$result['missing_items']); }
}
