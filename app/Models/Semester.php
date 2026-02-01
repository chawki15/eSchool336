<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Models\ClassSubject;

class Semester extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'id',
        'name',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date'
    ];

    protected $appends = ['current', 'start_month_name', 'end_month_name'];

    public function class_subjects()
    {
        return $this->hasMany(ClassSubject::class, 'semester_id', 'id')->with('subject');
    }

    public function getCurrentAttribute(): bool
    {
        $now = Carbon::now();

        // Handle semesters that wrap around the new year
        $start = $this->start_date;
        $end = $this->end_date;

        if (!$start || !$end) {
            return false;
        }

        if ($start->greaterThan($end)) {
            return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
        }

        return $now->between($start, $end);
    }

    public function getStartMonthNameAttribute(): string
    {
        return $this->start_date ? $this->start_date->translatedFormat('F') : '';
    }

    public function getEndMonthNameAttribute(): string
    {
        return $this->end_date ? $this->end_date->translatedFormat('F') : '';
    }
}
