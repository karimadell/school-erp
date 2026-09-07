<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleStaffAssignment extends Model
{
    public const ROLE_DRIVER = 'driver';

    public const ROLE_SUPERVISOR = 'supervisor';

    public const ROLE_STAFF_PASSENGER = 'staff_passenger';

    public const ROLES = [self::ROLE_DRIVER, self::ROLE_SUPERVISOR, self::ROLE_STAFF_PASSENGER];

    protected $fillable = [
        'bus_id', 'user_id', 'role', 'effective_from', 'effective_to', 'weekdays',
        'created_by', 'ended_by', 'change_reason',
    ];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'weekdays' => 'array'];

    public function bus()
    {
        return $this->belongsTo(Bus::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
