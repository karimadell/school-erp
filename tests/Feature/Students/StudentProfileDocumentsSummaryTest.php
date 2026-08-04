<?php
namespace Tests\Feature\Students;
use App\Models\StudentFile;
class StudentProfileDocumentsSummaryTest extends StudentProfileDashboardTestCase {
 public function test_e2a_document_summary_excludes_archive_and_has_no_upload_form():void { StudentFile::create(['student_id'=>$this->student->id,'title'=>'Паспорт','file_name'=>'p.pdf','file_path'=>'p.pdf','file_type'=>'application/pdf','file_size'=>1,'category'=>'other','type'=>'passport','expiry_date'=>today()->addDays(10)]); StudentFile::create(['student_id'=>$this->student->id,'title'=>'Медицинский','file_name'=>'m.pdf','file_path'=>'m.pdf','file_type'=>'application/pdf','file_size'=>1,'category'=>'other','type'=>'medical','archived_at'=>now()]); $this->actingAs($this->admin)->get(route('dashboard.students.show',$this->student))->assertOk()->assertSee('Активных документов:')->assertSee('В архиве:')->assertSee('Скоро истекает')->assertSee(route('dashboard.students.documents.index',$this->student))->assertDontSee('name="files[]"',false)->assertDontSee('enctype="multipart/form-data"',false); }
}
