<?php
namespace Tests\Feature\Students;
use App\Models\StudentFile; use Illuminate\Support\Facades\Storage;
class StudentDocumentArchiveTest extends StudentCompletionTestCase {
 public function test_archive_preserves_file_and_restore_works():void { Storage::fake('local'); Storage::disk('local')->put('doc.pdf','x'); $file=StudentFile::create(['student_id'=>$this->student->id,'title'=>'Справка','file_name'=>'doc.pdf','file_path'=>'doc.pdf','file_type'=>'application/pdf','file_size'=>1,'category'=>'other','type'=>'medical']); $this->actingAs($this->manager)->patch(route('dashboard.students.documents.archive',[$this->student,$file]),['archive_reason'=>'Новая версия'])->assertRedirect(); $this->assertNotNull($file->fresh()->archived_at); Storage::disk('local')->assertExists('doc.pdf'); $this->actingAs($this->manager)->patch(route('dashboard.students.documents.restore',[$this->student,$file]))->assertRedirect(); $this->assertNull($file->fresh()->archived_at); $this->actingAs($this->manager)->get(route('dashboard.students.documents.index',$this->student))->assertDontSee('Удалить'); }
}
