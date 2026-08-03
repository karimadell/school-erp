<?php
namespace Tests\Feature\Students;
use Illuminate\Http\UploadedFile; use Illuminate\Support\Facades\Storage;
class StudentProfileCompletionRollbackTest extends StudentCompletionTestCase {
 public function test_invalid_profile_does_not_store_photo_or_change_student():void { Storage::fake('public'); $this->actingAs($this->manager)->put(route('dashboard.students.complete-registration.update',$this->student),$this->profilePayload(['first_name_ru'=>'','photo'=>UploadedFile::fake()->image('new.jpg')]))->assertSessionHasErrors('first_name_ru'); $this->assertNull($this->student->fresh()->photo); $this->assertEmpty(Storage::disk('public')->allFiles()); }
}
