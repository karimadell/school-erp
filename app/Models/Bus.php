<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Bus extends Model
{
    protected $attributes = ['student_capacity' => 14, 'passenger_capacity' => 15];

    protected $fillable = [
        'vehicle_code',
        'name',
        'plate_number',
        'capacity',
        'student_capacity',
        'passenger_capacity',
        // Legacy compatibility only. New Transport writes use
        // VehicleStaffAssignment(user_id, role=driver).
        'driver_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'student_capacity' => 'integer',
        'passenger_capacity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $bus): void {
            if ($bus->student_capacity < 1 || $bus->student_capacity > 14) {
                throw ValidationException::withMessages(['student_capacity' => 'Вместимость для учеников должна быть от 1 до 14.']);
            }
            if ($bus->passenger_capacity < $bus->student_capacity) {
                throw ValidationException::withMessages(['passenger_capacity' => 'Физическая вместимость не может быть меньше ученической.']);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function studentTransportAssignments()
    {
        return $this->hasMany(StudentTransportAssignment::class);
    }

    public function staffAssignments()
    {
        return $this->hasMany(VehicleStaffAssignment::class);
    }
}
