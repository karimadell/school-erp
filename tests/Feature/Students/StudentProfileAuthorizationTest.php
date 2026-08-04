<?php
namespace Tests\Feature\Students;
class StudentProfileAuthorizationTest extends StudentProfileDashboardTestCase {
 public function test_current_permissions_control_profile_and_sensitive_sections():void { foreach(['super-admin','principal','admin','school-admin','reception','accountant'] as $role){$response=$this->actingAs($this->user($role))->get(route('dashboard.students.show',$this->student))->assertOk(); $role==='accountant'?$response->assertSee('Счета (')->assertDontSee('Открыть документы'):$response->assertSee('Профиль ученика');} $this->actingAs($this->user('teacher'))->get(route('dashboard.students.show',$this->student))->assertRedirect('/login'); $this->actingAs($this->user('none'))->get(route('dashboard.students.show',$this->student))->assertRedirect('/login'); $this->actingAs($this->user('admin',false))->get(route('dashboard.students.show',$this->student))->assertRedirect('/login'); }
}
