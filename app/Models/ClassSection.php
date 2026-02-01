<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Stream;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClassSection extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $fillable=[
        'id',
        'class_id',
        'section_id',
    ];
    protected $hidden = ["deleted_at", "created_at", "updated_at"];

    protected $appends = ["name", "full_name"];

    public function class() {
        return $this->belongsTo(ClassSchool::class)->withTrashed();
    }

    public function section() {
        return $this->belongsTo(Section::class)->withTrashed();
    }

    public function classTeachers()
    {
        return $this->belongsToMany(Teacher::class, 'class_teachers','class_section_id','class_teacher_id');
    }

    public function class_teachers()
    {
        return $this->hasMany(ClassTeacher::class,'class_section_id')->select('class_teacher_id');
    }

    public function streams(){
        return $this->belongsTo(Stream::class)->withTrashed();
    }

    public function announcement() {
        return $this->morphMany(Announcement::class, 'table');
    }

    public function subject_teachers() {
        return $this->hasMany(SubjectTeacher::class);
    }

    public function students() {
        return $this->hasMany(Students::class, 'class_section_id');
    }

    public function scopeClassTeacher($query) {
        $user = Auth::user();
        if ($user->hasRole('Teacher')) {
            $teacher = $user->teacher;
            return $query->where('class_teacher_id', $teacher->id);
        }
        return $query;
    }

    public function scopeSubjectTeacher($query) {
        $user = Auth::user();
        if ($user->hasRole('Teacher')) {
            $class_section_ids = $user->teacher->subjects()->pluck('class_section_id');
            return $query->whereIn('id', $class_section_ids);
        }
        return $query;
    }

    public function getNameAttribute() {
        $name = '';
        if ($this->relationLoaded('class')) {
            $name .= $this->class->name;
        }
        if ($this->relationLoaded('class.stream')) {
            $name .= isset($this->class->stream->name) ? ' (' . $this->class->stream->name . ') ' : '';
        }
        if ($this->relationLoaded('section')) {
            $name .= ' ' . $this->section->name;
        }
        return $name;
    }

    public function getFullNameAttribute() {
        $name = '';
        if ($this->relationLoaded('class')) {
            $name .= $this->class->name;
        }

        if ($this->relationLoaded('section')) {
            $name .= ' ' . $this->section->name;
        }
        if ($this->relationLoaded('class') && $this->class->relationLoaded('stream')) {
            $name .= isset($this->class->stream->name) ? ' ( ' . $this->class->stream->name . ' ) ' : '';
        }
        if ($this->relationLoaded('class') && $this->class->relationLoaded('medium')) {
            $name .= ' - ' . $this->class->medium->name;
        }
        return $name;
    }
}
