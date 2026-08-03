<?php
namespace Tests\Feature\Students;
use Illuminate\Http\UploadedFile; use Illuminate\Support\Facades\Storage;
class StudentDocumentUploadTest extends StudentCompletionTestCase {
 public function test_multiple_private_documents_and_metadata_are_saved():void { Storage::fake('local'); $this->actingAs($this->manager)->post(route('dashboard.students.documents.store',$this->student),['type'=>'medical','description'=>'Справка','issue_date'=>'2026-08-01','files'=>[UploadedFile::fake()->create('a.pdf',20,'application/pdf'),UploadedFile::fake()->image('b.jpg')]])->assertRedirect(); $this->assertDatabaseCount('student_files',2); $this->assertDatabaseHas('student_files',['description'=>'Справка','type'=>'medical','uploaded_by'=>$this->manager->id]); }
 public function test_executable_is_rejected():void { Storage::fake('local'); $this->actingAs($this->manager)->post(route('dashboard.students.documents.store',$this->student),['type'=>'other','files'=>[UploadedFile::fake()->create('x.php',5,'application/x-httpd-php')]])->assertSessionHasErrors('files.0'); }
}
