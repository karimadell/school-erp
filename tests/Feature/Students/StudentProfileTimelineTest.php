<?php
namespace Tests\Feature\Students;
use App\Models\AuditLog; use Illuminate\Support\Carbon;
class StudentProfileTimelineTest extends StudentProfileDashboardTestCase {
 public function test_real_events_are_newest_first_and_show_actor():void { Carbon::setTestNow('2026-08-05 10:00'); AuditLog::create(['user_id'=>$this->admin->id,'action'=>'profile_updated','model'=>'Student','model_id'=>$this->student->id]); Carbon::setTestNow('2026-08-06 10:00'); AuditLog::create(['user_id'=>$this->admin->id,'action'=>'submitted_for_review','model'=>'Student','model_id'=>$this->student->id]); Carbon::setTestNow(); $this->actingAs($this->admin)->get(route('dashboard.students.show',$this->student))->assertOk()->assertViewHas('profile',fn($profile)=>$profile['timeline']->pluck('at')->map->timestamp->values()->all()===$profile['timeline']->pluck('at')->map->timestamp->sortDesc()->values()->all())->assertSeeInOrder(['Отправка на проверку','Обновление личного дела'])->assertSee('Сотрудник: '.$this->admin->name)->assertDontSee('Выпуск ученика'); }
}
