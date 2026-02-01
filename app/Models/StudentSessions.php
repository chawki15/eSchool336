<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentSessions extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'class_section_id',
        'session_year_id',
        'previous_session_year_id'
    ];

    public function student()
    {
        return $this->belongsTo(Students::class);
    }
}
