<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffMasterImport extends Model
{
    protected $guarded = [];

    protected $casts = ['source_data' => 'array'];

    public function staffMember()
    {
        return $this->belongsTo(StaffMember::class);
    }
}
