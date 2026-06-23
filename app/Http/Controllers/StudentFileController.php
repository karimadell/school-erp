<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentFileController extends Controller
{
    /**
     * Upload student file
     */
    public function store(Request $request, Student $student)
    {
        // 🔒 Authorization (Policy)
        $this->authorize('create', StudentFile::class);

        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
            'type' => 'required|in:id,contract,report,other',
        ]);

        $file = $request->file('file');

        // 📁 Store file
        $path = $file->store(
            "students/{$student->id}",
            'public'
        );

        // 💾 Save in DB
        $student->files()->create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'type'      => $request->type,
        ]);

        return back()->with('success', 'File uploaded successfully');
    }

    /**
     * Delete student file
     */
    public function destroy(StudentFile $studentFile)
    {
        // 🔒 Authorization (Policy)
        $this->authorize('delete', $studentFile);

        // 🗑 Delete physical file
        Storage::disk('public')->delete($studentFile->file_path);

        // 🗑 Delete DB record
        $studentFile->delete();

        return back()->with('success', 'File deleted successfully');
    }
}