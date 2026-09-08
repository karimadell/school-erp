<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    protected $fillable = ['name', 'pricing_zone', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function studentAssignments()
    {
        return $this->hasMany(StudentTransportAssignment::class);
    }

    public function buses()
    {
        return $this->hasMany(Bus::class, 'transport_route_id');
    }
}
