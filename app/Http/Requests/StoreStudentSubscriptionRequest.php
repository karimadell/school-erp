<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentSubscriptionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage students') === true; }
    public function rules(): array { return ['academic_year_id'=>['required','integer','exists:academic_years,id'],'fee_id'=>['required','integer','exists:fees,id'],'start_date'=>['required','date'],'end_date'=>['nullable','date','after_or_equal:start_date'],'quantity'=>['required','integer','min:1','max:100'],'metadata'=>['nullable','array'],'reason'=>['nullable','string','max:1000']]; }
    public function messages(): array { return ['academic_year_id.required'=>'Выберите учебный год.','fee_id.required'=>'Выберите услугу.','start_date.required'=>'Укажите дату начала услуги.','end_date.date'=>'Дата окончания указана неверно.','end_date.after_or_equal'=>'Дата окончания не может быть раньше даты начала.','quantity.min'=>'Количество должно быть больше нуля.']; }
}
