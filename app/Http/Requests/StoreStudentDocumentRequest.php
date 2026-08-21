<?php

namespace App\Http\Requests;

use App\Models\StudentFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isActive() && $this->user()->can('manage students'); }
    public function rules(): array
    {
        $studentId = $this->route('student')->id;

        return ['files' => ['required', 'array', 'min:1', 'max:10'], 'files.*' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:2048'], 'type' => ['required_without:document_type_id', Rule::in(array_keys(StudentFile::TYPES))], 'document_type_id' => ['nullable', 'exists:document_types,id'], 'student_representative_id' => ['nullable', Rule::exists('student_representatives', 'id')->where('student_id', $studentId)], 'enrollment_id' => ['nullable', Rule::exists('enrollments', 'id')->where('student_id', $studentId)], 'series' => ['nullable', 'string', 'max:100'], 'document_number' => ['nullable', 'string', 'max:100'], 'issued_by' => ['nullable', 'string', 'max:1000'], 'subdivision_code' => ['nullable', 'string', 'max:50'], 'issuing_country_code' => ['nullable', 'string', 'size:2'], 'description' => ['nullable', 'string', 'max:1000'], 'issue_date' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date']];
    }

    public function messages(): array
    {
        return [
            'files.required' => __('student_registration.validation.documents.files_required'),
            'files.*.mimetypes' => __('student_registration.validation.documents.file_mimetypes'),
            'files.*.max' => __('student_registration.validation.documents.file_max'),
            'type.required' => __('student_registration.validation.documents.type_required'),
            'expiry_date.after_or_equal' => __('student_registration.validation.documents.expiry_after_issue'),
        ];
    }
}
