<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Semester;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClassSubject extends Model
{
    use HasFactory;

    protected $hidden = ["deleted_at", "created_at", "updated_at"];

    public function class() {
        return $this->belongsTo(ClassSchool::class);
    }

    public function subject() {
        return $this->belongsTo(Subject::class)->where('deleted_at',null);
    }

    public function subjectGroup() {
        return $this->belongsTo(ElectiveSubjectGroup::class, 'elective_subject_group_id');
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function scopeSubjectTeacher($query, $class_section_id = null) {
        $currentSemester = Semester::get()->first(function ($semester) {
            return $semester->current;
        });
        $user = Auth::user();
        if ($user->hasRole('Teacher')) {
            if ($class_section_id) {
                $subjects_ids = $user->teacher->subjects()->where('class_section_id', $class_section_id)->pluck('subject_id');
            } else {
                $subjects_ids = $user->teacher->subjects()->pluck('subject_id');
            }
            return $query->whereIn('subject_id', $subjects_ids);
        }
        return $query;
    }
}
