<?php
namespace Tests\Feature\Students;
use App\Models\StudentFile; use App\Services\Students\StudentProfileCompletionService;
class StudentDocumentExpiryTest extends StudentCompletionTestCase {
 public function test_expired_identity_does_not_satisfy_shared_requirement():void { $file=StudentFile::create(['student_id'=>$this->student->id,'title'=>'Паспорт','file_name'=>'p.pdf','file_path'=>'p.pdf','file_type'=>'application/pdf','file_size'=>1,'category'=>'other','type'=>'passport','expiry_date'=>today()->subDay()]); $this->assertSame('Просрочен',$file->expiryStatus()); $result=app(StudentProfileCompletionService::class)->calculate($this->student->fresh()); $this->assertContains('Паспорт или вид на жительство',$result['missing_items']); $file->update(['type'=>'residence_permit','expiry_date'=>today()->addYear()]); $this->assertNotContains('Паспорт или вид на жительство',app(StudentProfileCompletionService::class)->calculate($this->student->fresh())['missing_items']); }
}
