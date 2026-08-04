<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentServiceSubscriptionEvent extends Model
{
    protected $fillable = ['subscription_id', 'event_type', 'effective_date', 'reason', 'metadata', 'created_by'];
    protected $casts = ['effective_date' => 'date', 'metadata' => 'array'];

    public function subscription() { return $this->belongsTo(StudentServiceSubscription::class, 'subscription_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
