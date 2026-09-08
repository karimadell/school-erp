<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffMember extends Model
{
    protected $attributes = ['is_active' => true];

    protected $casts = ['is_active' => 'boolean'];

    protected $fillable = ['display_name', 'phone', 'user_id', 'is_active'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transportAssignments()
    {
        return $this->hasMany(VehicleStaffAssignment::class);
    }

    public function bootstrapImports()
    {
        return $this->hasMany(StaffTransportBootstrapImport::class);
    }

    public function masterImports()
    {
        return $this->hasMany(StaffMasterImport::class);
    }
}
