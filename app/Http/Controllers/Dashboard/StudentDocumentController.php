<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArchiveStudentDocumentRequest;
use App\Http\Requests\StoreStudentDocumentRequest;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Student;
use App\Models\StudentFile;
use App\Services\Students\StudentProfileCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentDocumentController extends Controller
{
    public function __construct(private StudentProfileCompletionService $completion) { $this->middleware('permission:manage students'); }

    public function index(Student $student)
    {
        $student->load(['files.uploader', 'files.archiver', 'files.documentType', 'currentEnrollment', 'representatives', 'emergencyContacts']);

        return view('dashboard.students.documents', ['student' => $student, 'activeFiles' => $student->files->whereNull('archived_at'), 'archivedFiles' => $student->files->whereNotNull('archived_at'), 'documentTypes' => DocumentType::where('is_active', true)->orderBy('sort_order')->get(), 'completion' => $this->completion->calculate($student)]);
    }

    public function store(StoreStudentDocumentRequest $request, Student $student)
    {
        $paths=[];
        try {
            foreach ($request->file('files') as $file) {
                $paths[] = ['path' => $file->store("students/{$student->id}/documents", config('filesystems.uploads.private')), 'file' => $file];
            }
            DB::transaction(function () use ($paths, $request, $student) {
                $documentType = $request->document_type_id ? DocumentType::findOrFail($request->document_type_id) : DocumentType::where('code', $request->type)->first();
                $legacyType = $documentType?->code ?? $request->type ?? 'other';
                foreach ($paths as $stored) {
                    $file = $stored['file'];
                    $record = $student->files()->create(['title' => $documentType?->name_ru ?? StudentFile::TYPES[$legacyType] ?? StudentFile::TYPES['other'], 'file_name' => $file->getClientOriginalName(), 'file_path' => $stored['path'], 'file_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'category' => 'other', 'type' => $legacyType, 'document_type_id' => $documentType?->id, 'student_representative_id' => $request->student_representative_id, 'enrollment_id' => $request->enrollment_id, 'series' => $request->series, 'document_number' => $request->document_number, 'issued_by' => $request->issued_by, 'subdivision_code' => $request->subdivision_code, 'issuing_country_code' => $request->issuing_country_code ? strtoupper($request->issuing_country_code) : null, 'description' => $request->description, 'issue_date' => $request->issue_date, 'expiry_date' => $request->expiry_date, 'uploaded_by' => $request->user()->id]);
                    $this->audit($request, 'document_uploaded', $record);
                }
            });
        } catch (\Throwable $e) { foreach ($paths as $stored) Storage::disk(config('filesystems.uploads.private'))->delete($stored['path']); throw $e; }
        return back()->with('success','Файл успешно добавлен.');
    }

    public function preview(Student $student, StudentFile $studentFile) { return $this->stream($student,$studentFile,'inline'); }
    public function download(Student $student, StudentFile $studentFile) { return $this->stream($student,$studentFile,'attachment'); }

    public function archive(ArchiveStudentDocumentRequest $request, Student $student, StudentFile $studentFile)
    {
        $this->owned($student,$studentFile); $studentFile->update(['archived_at'=>now(),'archived_by'=>$request->user()->id,'archive_reason'=>$request->archive_reason]); $this->audit($request,'document_archived',$studentFile); return back()->with('success','Документ перемещён в архив.');
    }
    public function restore(Request $request, Student $student, StudentFile $studentFile)
    {
        $this->owned($student,$studentFile); $studentFile->update(['archived_at'=>null,'archived_by'=>null,'archive_reason'=>null]); $this->audit($request,'document_restored',$studentFile); return back()->with('success','Документ восстановлен.');
    }

    private function stream(Student $student, StudentFile $file, string $disposition)
    {
        $this->owned($student,$file); $this->authorize('view',$file); $disk=Storage::disk(config('filesystems.uploads.private'));
        if (! $disk->exists($file->file_path)) abort(404);
        return response()->stream(fn()=>print($disk->get($file->file_path)), 200, ['Content-Type'=>$file->file_type,'Content-Disposition'=>$disposition.'; filename="'.addslashes($file->file_name).'"']);
    }
    private function owned(Student $student, StudentFile $file): void { abort_unless($file->student_id===$student->id,404); }
    private function audit(Request $request,string $action,StudentFile $file): void { AuditLog::create(['user_id'=>$request->user()->id,'action'=>$action,'model'=>'StudentFile','model_id'=>$file->id,'new_values'=>['student_id'=>$file->student_id,'type'=>$file->type],'ip'=>$request->ip(),'user_agent'=>$request->userAgent()]); }
}
