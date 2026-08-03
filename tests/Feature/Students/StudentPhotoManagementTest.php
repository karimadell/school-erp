<?php
namespace Tests\Feature\Students;
use Illuminate\Http\UploadedFile; use Illuminate\Support\Facades\Storage;
class StudentPhotoManagementTest extends StudentCompletionTestCase {
 public function test_photo_upload_and_preservation_work_without_student_file():void { Storage::fake('public'); $this->actingAs($this->manager)->put(route('dashboard.students.complete-registration.update',$this->student),$this->profilePayload(['photo'=>UploadedFile::fake()->image('student.webp')]))->assertRedirect(); $path=$this->student->fresh()->photo; Storage::disk('public')->assertExists($path); $this->assertDatabaseCount('student_files',0); $this->actingAs($this->manager)->put(route('dashboard.students.complete-registration.update',$this->student),$this->profilePayload())->assertRedirect(); $this->assertSame($path,$this->student->fresh()->photo); }
 public function test_invalid_photo_has_russian_error():void { $this->actingAs($this->manager)->put(route('dashboard.students.complete-registration.update',$this->student),$this->profilePayload(['photo'=>UploadedFile::fake()->create('bad.exe',10,'application/x-msdownload')]))->assertSessionHasErrors('photo'); }
 public function test_oversized_photo_is_rejected():void { $this->actingAs($this->manager)->put(route('dashboard.students.complete-registration.update',$this->student),$this->profilePayload(['photo'=>UploadedFile::fake()->create('big.jpg',2049,'image/jpeg')]))->assertSessionHasErrors('photo'); }
}
