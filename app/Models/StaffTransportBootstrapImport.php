<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffTransportBootstrapImport extends Model
{
    protected $fillable = [
        'source_key', 'source_file', 'source_sheet', 'source_row', 'raw_display_name',
        'raw_pickup_point', 'raw_contact', 'raw_notes', 'route', 'staff_member_id',
        'vehicle_staff_assignment_id',
    ];

    public function staffMember()
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function vehicleStaffAssignment()
    {
        return $this->belongsTo(VehicleStaffAssignment::class, 'vehicle_staff_assignment_id');
    }
}
