<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ArchiveStudentDocumentRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->isActive() && $this->user()->can('manage students'); }
    public function rules(): array { return ['archive_reason'=>['required','string','max:255']]; }
    public function messages(): array { return ['archive_reason.required'=>'Укажите причину архивирования.']; }
}
