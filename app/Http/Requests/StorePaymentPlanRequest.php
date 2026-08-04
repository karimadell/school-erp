<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentPlanRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage invoices') === true; }
    protected function prepareForValidation(): void { $this->merge(['is_active'=>$this->boolean('is_active')]); }
    public function rules(): array
    {
        return ['name_ru'=>['required','string','max:150'],'description'=>['nullable','string','max:1000'],'is_active'=>['required','boolean'],
            'sort_order'=>['required','integer','min:0'],'installments'=>['required','array','min:1'],
            'installments.*.name_ru'=>['required','string','max:150'],'installments.*.offset_days'=>['required','integer','min:0'],
            'installments.*.percentage'=>['required','decimal:0,4','gt:0']];
    }
    public function after(): array
    {
        return [function ($validator): void {
            $sum = collect($this->input('installments',[]))->reduce(fn($sum,$row)=>bcadd($sum,(string)($row['percentage']??0),4),'0.0000');
            if (bccomp($sum,'100.0000',4)!==0) $validator->errors()->add('installments','Сумма долей этапов должна составлять 100%.');
        }];
    }
    public function messages(): array { return ['name_ru.required'=>'Укажите название плана.','installments.required'=>'Добавьте хотя бы один этап оплаты.','installments.*.name_ru.required'=>'Укажите название этапа.','installments.*.percentage.gt'=>'Доля этапа должна быть больше нуля.']; }
}
