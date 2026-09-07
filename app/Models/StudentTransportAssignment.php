<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentTransportAssignment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $fillable = [
        'enrollment_id', 'transport_route_id', 'bus_id', 'pricing_zone', 'pickup_point',
        'billing_period', 'effective_from', 'effective_to', 'status', 'created_by',
        'ended_by', 'change_reason',
    ];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function route()
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function bus()
    {
        return $this->belongsTo(Bus::class);
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
