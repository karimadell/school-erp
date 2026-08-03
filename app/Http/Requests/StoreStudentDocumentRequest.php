<?php

namespace App\Http\Requests;

use App\Models\StudentFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isActive() && $this->user()->can('manage students'); }
    public function rules(): array { return ['files'=>['required','array','min:1','max:10'],'files.*'=>['required','file','mimetypes:application/pdf,image/jpeg,image/png,image/webp','max:2048'],'type'=>['required',Rule::in(array_keys(StudentFile::TYPES))],'description'=>['nullable','string','max:1000'],'issue_date'=>['nullable','date'],'expiry_date'=>['nullable','date','after_or_equal:issue_date']]; }
    public function messages(): array { return ['files.required'=>'Выберите хотя бы один файл.','files.*.mimetypes'=>'Разрешены только PDF, JPG, JPEG, PNG и WEBP.','files.*.max'=>'Размер каждого файла не должен превышать 2 МБ.','type.required'=>'Выберите тип документа.','expiry_date.after_or_equal'=>'Срок действия не может быть раньше даты выдачи.']; }
}
