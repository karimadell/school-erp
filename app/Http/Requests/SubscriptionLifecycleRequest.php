<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionLifecycleRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage students') === true; }
    public function rules(): array { return ['effective_date'=>['required','date'],'reason'=>['required_if:action,pause,end','nullable','string','max:1000']]; }
    public function messages(): array { return ['effective_date.required'=>'Укажите дату действия.','reason.required_if'=>'Укажите причину изменения.']; }
}
