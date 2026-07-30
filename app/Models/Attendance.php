<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model implements ResolvesAcademicYear
{
    protected $fillable = [
        'enrollment_id',
        'period_id',
        'date',
        'type',
        'status',
        'note',
        'attendance_key',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return Enrollment::find($this->enrollment_id)?->resolveAcademicYear();
    }

    /**
     * Matches the key format AttendanceController::store() already
     * generates ("daily-{enrollment}-{date}" / "period-{enrollment}-{date}-{period}"),
     * so the same enrollment/date/(period) combination resolves to the
     * same row regardless of which surface wrote it.
     */
    public static function buildAttendanceKey(string $type, int $enrollmentId, string $date, ?int $periodId = null): string
    {
        return $type === 'period'
            ? "period-{$enrollmentId}-{$date}-{$periodId}"
            : "daily-{$enrollmentId}-{$date}";
    }
}
