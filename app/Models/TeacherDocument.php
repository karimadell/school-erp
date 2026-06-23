<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherDocument extends Model
{
    protected $table = 'teacher_documents';

    protected $fillable = [
        'teacher_id',
        'title',
        'file_path',
        'file_type',
        'document_date',
    ];

    protected $casts = [
        'document_date' => 'date',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * Document → Teacher
     */
    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers (احترافي)
    |--------------------------------------------------------------------------
    */

    /**
     * Full File URL
     */
    public function getFileUrlAttribute()
    {
        return asset('storage/' . $this->file_path);
    }

    /**
     * File Name Only
     */
    public function getFileNameAttribute()
    {
        return basename($this->file_path);
    }

    /**
     * File Icon حسب النوع
     */
    public function getFileIconAttribute()
    {
        $type = strtolower($this->file_type);

        return match (true) {
            str_contains($type, 'pdf') => '📄',
            str_contains($type, 'image') => '🖼',
            str_contains($type, 'word') => '📝',
            str_contains($type, 'excel') => '📊',
            default => '📎',
        };
    }

    /**
     * Formatted Date
     */
    public function getFormattedDateAttribute()
    {
        return $this->document_date
            ? $this->document_date->format('Y-m-d')
            : null;
    }
}