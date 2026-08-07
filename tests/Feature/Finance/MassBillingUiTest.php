<?php

namespace Tests\Feature\Finance;

use App\Models\BillingBatch;
use App\Models\BillingRun;
use App\Models\User;

class MassBillingUiTest extends MassBillingTestCase
{
    private function viewer(): User
    {
        // Administrative user who may view/manage Mass Billing but may NOT execute.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');
        $user->givePermissionTo('view mass billing');
        $user->givePermissionTo('manage mass billing');

        return $user;
    }

    private function previewedBatch(): BillingBatch
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        app(\App\Services\Finance\MassBillingPreviewService::class)->preview($batch);

        return $batch->fresh();
    }

    // ----- Navigation -----------------------------------------------------

    public function test_authorized_user_sees_mass_billing_navigation_label(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Массовое начисление');
    }

    public function test_navigation_is_hidden_for_user_without_view_permission(): void
    {
        // reception is administrative but has no Mass Billing permission.
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');

        $this->actingAs($reception)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee('Массовое начисление');
    }

    // ----- Route authorization --------------------------------------------

    public function test_index_requires_authentication(): void
    {
        $this->get(route('dashboard.finance.mass-billing.index'))->assertRedirect();
    }

    public function test_user_without_view_permission_cannot_open_index(): void
    {
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');

        $this->actingAs($reception)
            ->get(route('dashboard.finance.mass-billing.index'))
            ->assertForbidden();
    }

    public function test_user_without_manage_permission_cannot_open_create(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('reception');
        $viewer->givePermissionTo('view mass billing');

        $this->actingAs($viewer)
            ->get(route('dashboard.finance.mass-billing.create'))
            ->assertForbidden();
    }

    // ----- Execute button visibility --------------------------------------

    public function test_previewed_batch_shows_execute_button_for_authorized_user(): void
    {
        $batch = $this->previewedBatch();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.mass-billing.show', $batch))
            ->assertOk()
            ->assertSee('Создать счета');
    }

    public function test_execute_button_is_hidden_for_user_without_execute_permission(): void
    {
        $batch = $this->previewedBatch();

        $this->actingAs($this->viewer())
            ->get(route('dashboard.finance.mass-billing.show', $batch))
            ->assertOk()
            ->assertSee('Предварительный просмотр') // page renders
            ->assertDontSee('Создать счета');       // but no execute action
    }

    public function test_completed_batch_has_no_execute_button(): void
    {
        $batch = $this->previewedBatch();
        app(\App\Services\Finance\MassBillingExecutionService::class)->execute($batch, $this->accountant);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.mass-billing.show', $batch->fresh()))
            ->assertOk()
            ->assertDontSee('Создать счета')
            ->assertSee('Результаты выполнения');
    }

    public function test_failed_batch_shows_failure_and_no_retry_button(): void
    {
        $batch = $this->previewedBatch();
        $batch->update(['status' => BillingBatch::STATUS_FAILED]);
        $batch->runs()->create([
            'status' => BillingRun::STATUS_FAILED,
            'executed_by' => $this->accountant->id,
            'started_at' => now(),
            'finished_at' => now(),
            'failure_summary' => ['code' => 'execution_failed'],
        ]);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.mass-billing.show', $batch->fresh()))
            ->assertOk()
            ->assertSee('Операция завершена с ошибкой')
            ->assertDontSee('Создать счета');
    }

    // ----- Russian result labels ------------------------------------------

    public function test_completed_result_screen_shows_russian_labels_and_invoice_link(): void
    {
        $batch = $this->previewedBatch();
        app(\App\Services\Finance\MassBillingExecutionService::class)->execute($batch, $this->accountant);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.mass-billing.show', $batch->fresh()))
            ->assertOk()
            ->assertSee('Результаты выполнения')
            ->assertSee('Создано счетов')
            ->assertSee('Общая сумма')
            ->assertSee('Исполнитель')
            ->assertSee('Дата выполнения')
            ->assertSee('Просмотр счёта')
            ->assertSee('Перед созданием счетов данные и тарифы будут проверены повторно.');
    }

    // ----- Validation -----------------------------------------------------

    public function test_validation_errors_use_russian_translation_keys(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.mass-billing.store'), [
                'academic_year_id' => $this->year->id,
                'fee_id' => $this->tuition->id,
                'quantity' => 0, // invalid
                'issue_date' => '2026-09-10',
                'due_date' => '2026-09-01', // before issue date
                'target_mode' => BillingBatch::TARGET_MODE_CLASSES,
                // class_ids omitted while target_mode = classes
            ])
            ->assertSessionHasErrors(['quantity', 'due_date', 'class_ids']);

        $errors = session('errors');
        $this->assertSame(__('mass_billing.validation.classes_required'), $errors->first('class_ids'));
        $this->assertSame(__('mass_billing.validation.due_after_issue'), $errors->first('due_date'));
        // Default rule message resolves in Russian with the friendly attribute
        // name (not the raw rule key / raw field name).
        $this->assertStringContainsString('не менее', $errors->first('quantity'));
        $this->assertStringContainsString(__('mass_billing.fields.quantity'), $errors->first('quantity'));
    }

    public function test_price_override_fields_remain_prohibited(): void
    {
        $this->enrolledStudent($this->classA, suffix: 'A1');

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.mass-billing.store'), [
                'academic_year_id' => $this->year->id, 'fee_id' => $this->tuition->id, 'quantity' => 1,
                'issue_date' => '2026-09-01', 'due_date' => '2027-01-01', 'target_mode' => BillingBatch::TARGET_MODE_CLASSES,
                'class_ids' => [$this->classA->id], 'unit_price' => '5.00',
            ])
            ->assertSessionHasErrors('unit_price');
    }

    // ----- Translation key parity -----------------------------------------

    public function test_ru_en_ar_mass_billing_keys_are_in_parity(): void
    {
        $flatten = function (array $a, string $p = '') use (&$flatten): array {
            $keys = [];
            foreach ($a as $k => $v) {
                $key = $p === '' ? (string) $k : "{$p}.{$k}";
                $keys = is_array($v) ? array_merge($keys, $flatten($v, $key)) : array_merge($keys, [$key]);
            }
            return $keys;
        };

        $ru = $flatten(require lang_path('ru/mass_billing.php'));
        $en = $flatten(require lang_path('en/mass_billing.php'));
        $ar = $flatten(require lang_path('ar/mass_billing.php'));
        sort($ru);
        sort($en);
        sort($ar);

        $this->assertSame($ru, $en, 'EN keys must match RU keys.');
        $this->assertSame($ru, $ar, 'AR keys must match RU keys.');
    }
}
