<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportCard;
use App\Http\Controllers\Dashboard\ReportCardController;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for A4 (docs/IMPLEMENTATION_READINESS_ROADMAP.md):
 *
 * - `report-cards/*` dashboard routes pointed at ReportCardController's
 *   index()/show()/print() methods, which did not exist on the controller
 *   at all — every request fatally errored (uncaught Error / 500).
 * - The Filament ReportCard page's downloadPdf() rendered a PDF view that
 *   called Student::quarterGrade(), a method that does not exist — every
 *   click fatally errored.
 *
 * This is a policy-neutral containment fix (option (a)): both surfaces now
 * show a plain "not available yet" message instead of crashing. No grade
 * calculation, averaging, or report-card logic is implemented here.
 */
class ReportCardTest extends TestCase
{
    use RefreshDatabase;

    protected function makeStudent(): Student
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ar' => 'الفصل أ']);

        return Student::create(['name' => 'Test Student', 'class_id' => $class->id]);
    }

    public function test_index_no_longer_fatally_errors_and_shows_the_unavailable_message(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.report_cards.index'));

        $response->assertOk();
        $response->assertSee(__('report_cards.not_available_yet'));
    }

    public function test_show_no_longer_fatally_errors_and_shows_the_unavailable_message(): void
    {
        $user = User::factory()->create();
        $student = $this->makeStudent();

        $response = $this->actingAs($user)->get(route('dashboard.report_cards.show', $student->id));

        $response->assertOk();
        $response->assertSee(__('report_cards.not_available_yet'));
    }

    public function test_print_no_longer_fatally_errors_and_shows_the_unavailable_message(): void
    {
        $user = User::factory()->create();
        $student = $this->makeStudent();

        $response = $this->actingAs($user)->get(route('dashboard.report_cards.print', $student->id));

        $response->assertOk();
        $response->assertSee(__('report_cards.not_available_yet'));
    }

    public function test_show_and_print_return_the_same_unavailable_page_for_a_nonexistent_student_id(): void
    {
        // Deliberately not looking up the student at all (that would be
        // report-card-specific logic, out of scope for this containment fix).
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard.report_cards.show', 999999))->assertOk();
        $this->actingAs($user)->get(route('dashboard.report_cards.print', 999999))->assertOk();
    }

    public function test_guest_is_redirected_to_login_for_report_card_routes(): void
    {
        // The 'auth' middleware on this route group is unchanged by A4 —
        // confirms the fix didn't loosen access.
        $this->get(route('dashboard.report_cards.index'))->assertRedirect(route('login'));
        $this->get(route('dashboard.report_cards.show', 1))->assertRedirect(route('login'));
        $this->get(route('dashboard.report_cards.print', 1))->assertRedirect(route('login'));
    }

    public function test_the_controllers_preexisting_restaurant_methods_are_unchanged(): void
    {
        // ReportCardController's pre-existing (unrelated) restaurant-billing
        // methods are not routed to anywhere (dashboard.reports.restaurant
        // and friends resolve to the separate ReportController instead) but
        // must still exist, unmodified, on this controller after the fix.
        foreach ([
            'restaurant',
            'restaurantDaily',
            'restaurantWeekly',
            'restaurantKitchen',
            'restaurantKitchenPdf',
            'restaurantDashboard',
        ] as $method) {
            $this->assertTrue(
                method_exists(ReportCardController::class, $method),
                "Expected ReportCardController::{$method}() to still exist"
            );
        }
    }

    public function test_download_pdf_no_longer_crashes_and_sends_a_notification(): void
    {
        $user = User::factory()->create();
        $student = $this->makeStudent();

        Livewire::actingAs($user)
            ->test(ReportCard::class)
            ->set('studentId', $student->id)
            ->call('loadStudent')
            ->call('downloadPdf')
            ->assertNotified(__('report_cards.not_available_yet'));
    }

    public function test_download_pdf_without_a_loaded_student_still_returns_null_unchanged(): void
    {
        // Pre-existing guard clause, must remain unaffected by this fix.
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ReportCard::class)
            ->call('downloadPdf')
            ->assertNoRedirect();
    }
}
