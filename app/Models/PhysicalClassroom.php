<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class PhysicalClassroom extends Model implements ResolvesAcademicYear
{
    public const TYPE_CLASSROOM = 'classroom';

    public const TYPE_LABORATORY = 'laboratory';

    public const TYPE_COMPUTER_LAB = 'computer_lab';

    public const TYPE_SCIENCE_LAB = 'science_lab';

    public const TYPE_ART_ROOM = 'art_room';

    public const TYPE_MUSIC_ROOM = 'music_room';

    public const TYPE_LIBRARY = 'library';

    public const TYPE_SPORTS_HALL = 'sports_hall';

    public const TYPE_AUDITORIUM = 'auditorium';

    public const TYPE_EXAM_ROOM = 'exam_room';

    public const TYPE_MEETING_ROOM = 'meeting_room';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_CLASSROOM,
        self::TYPE_LABORATORY,
        self::TYPE_COMPUTER_LAB,
        self::TYPE_SCIENCE_LAB,
        self::TYPE_ART_ROOM,
        self::TYPE_MUSIC_ROOM,
        self::TYPE_LIBRARY,
        self::TYPE_SPORTS_HALL,
        self::TYPE_AUDITORIUM,
        self::TYPE_EXAM_ROOM,
        self::TYPE_MEETING_ROOM,
        self::TYPE_OTHER,
    ];

    protected $table = 'classrooms';

    protected $fillable = [
        'academic_year_id', 'building', 'floor', 'code', 'name', 'capacity',
        'room_type', 'is_active', 'notes',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'classroom' => __('classroom.validation.hard_delete_forbidden'),
            ]);
        });

        static::saving(function (self $classroom): void {
            $errors = [];

            if (($classroom->capacity ?? 0) < 1) {
                $errors['capacity'] = __('classroom.validation.capacity_min');
            }

            if (! in_array($classroom->room_type, self::TYPES, true)) {
                $errors['room_type'] = __('classroom.validation.invalid_room_type');
            }

            $duplicateCode = static::query()
                ->where('academic_year_id', $classroom->academic_year_id)
                ->where('code', $classroom->code)
                ->when($classroom->exists, fn ($query) => $query->whereKeyNot($classroom->getKey()))
                ->exists();

            if ($duplicateCode) {
                $errors['code'] = __('classroom.validation.duplicate_code');
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        });
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->academicYear()->first();
    }
}
