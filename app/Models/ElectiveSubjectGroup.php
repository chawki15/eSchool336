<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Subject;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ElectiveSubjectGroup extends Model
{
    use HasFactory;

    protected $hidden = ["deleted_at", "created_at", "updated_at"];

    public function electiveSubjects() {
        // return $this->belongsToMany(Subject::class, ClassSubject::class, 'elective_subject_group_id', 'subject_id')->wherePivot('type', 'Elective')->withPivot('id as subject_id')->where('class_subjects.deleted_at',null)->withTrashed();
        return $this->hasMany(ClassSubject::class, 'elective_subject_group_id')->with('semester');
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

}
