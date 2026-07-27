<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AcademicYear extends Model
{
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * At most one AcademicYear may be active at a time (zero is allowed).
     * Activating this year must atomically deactivate every other active
     * year — locked and updated inside the same transaction as this row's
     * own save, so the two writes commit or fail together. Saving a year
     * with is_active = false never touches any other row.
     */
    public function save(array $options = [])
    {
        if (! $this->is_active) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options) {
            static::query()
                ->where('is_active', true)
                ->when($this->exists, fn ($query) => $query->where('id', '!=', $this->id))
                ->lockForUpdate()
                ->get()
                ->each(function (self $year) {
                    $year->is_active = false;
                    $year->save();
                });

            return parent::save($options);
        });
    }
}
