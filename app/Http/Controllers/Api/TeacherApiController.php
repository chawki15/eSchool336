<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Exception;
use Throwable;
use Carbon\Carbon;
use App\Models\Event;
use App\Models\MultipleEvent;
use App\Models\Exam;
use App\Models\File;
use App\Models\Grade;
use App\Models\Leave;
use App\Models\Lesson;
use App\Models\Holiday;
use App\Models\Parents;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\ChatFile;
use App\Models\Students;
use App\Models\ExamClass;
use App\Models\ExamMarks;
use App\Models\Timetable;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\ChatMessage;
use App\Models\LeaveDetail;
use App\Models\LeaveMaster;
use App\Models\LessonTopic;
use App\Models\ReadMessage;
use App\Models\SessionYear;
use App\Models\Announcement;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\ClassTeacher;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\ExamTimetable;
use App\Models\StudentSubject;
use App\Models\SubjectTeacher;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\UserNotification;
use App\Rules\uniqueLessonInClass;
use App\Rules\uniqueTopicInLesson;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TeacherApiController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            $auth = Auth::user();

            if (!$auth->hasRole('Teacher')) {
                ResponseService::errorResponse('Invalid Login Credentials', null, 101);
            }
            $token = $auth->createToken($auth->first_name)->plainTextToken;
            $user = $auth->load(['teacher']);


            $dynamicFields = null;
            $dynamicField = $user->teacher->dynamic_fields;
            $user = flattenMyModel($user);
            if (!empty($dynamicField)) {
                $data = json_decode($dynamicField, true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if ($item != null) {
                            foreach ($item as $key => $value) {
                                $dynamicFields[$key] = $value;
                            }
                        }
                    }
                } else {
                    $dynamicFields = $data;
                }
            } else {
                $dynamicFields = null;
            }


            $user = array_merge($user, ['dynamic_fields' =>  $dynamicFields]);

            if ($request->fcm_id) {
                $auth->fcm_id = $request->fcm_id;
                $auth->save();
            }
            if ($request->device_type) {
                $auth->device_type = $request->device_type;
                $auth->save();
            }
            ResponseService::successResponse('User logged-in!', $user, ['token' => $token], 100);
        } else {
            ResponseService::errorResponse('Invalid Login Credentials', null, 101);
        }
    }

    /**
     * Get academic calendar PDF combining holidays, events, and exams.
     */
    public function getAcademicCalendarPdf()
    {
        try {
            // === Get Current Session Year ===
            $session_year_id = getSettings('session_year')['session_year'];
            $session_year = DB::table('session_years')
                ->select('name', 'start_date', 'end_date')
                ->where('id', $session_year_id)
                ->first();

            if (!$session_year) {
                throw new \Exception('Session year not found.');
            }

            $semesterBreaks = collect();

            $allSemesters = Semester::query()
                ->whereBetween('start_date', [
                    $session_year->start_date,
                    $session_year->end_date
                ])
                ->orderBy('start_date', 'asc')
                ->get();

            // Find the current semester using your model's accessor
            $currentSemester = $allSemesters->first(fn($semester) => $semester->current === true);

            // Loop through all semesters to find every valid break
            for ($i = 0; $i < $allSemesters->count() - 1; $i++) {
                $current = $allSemesters[$i];
                $next = $allSemesters[$i + 1];

                if (
                    $current->end_date &&
                    $next->start_date &&
                    Carbon::parse($next->start_date)->gt(Carbon::parse($current->end_date))
                ) {
                    $breakStart = Carbon::parse($current->end_date)->addDay();
                    $breakEnd   = Carbon::parse($next->start_date)->subDay();

                    // Make sure the break falls inside the session year
                    if (
                        $breakStart->between($session_year->start_date, $session_year->end_date) &&
                        $breakEnd->between($session_year->start_date, $session_year->end_date)
                    ) {
                        $semesterBreaks->push((object)[
                            'date' => $breakStart->toDateString(),
                            'start' => $breakStart->toDateString(),
                            'end' => $breakEnd->toDateString(),
                            'type'  => 'holiday',
                            'title' => "Semester Break: {$breakStart->format('jS M')} - {$breakEnd->format('jS M')}",
                        ]);
                    }
                }
            }

            // If there’s no current semester (it’s a break), identify which break we’re in
            if (!$currentSemester && $semesterBreaks->isNotEmpty()) {
                $now = Carbon::today();

                $activeBreak = $semesterBreaks->first(function ($break) use ($now) {
                    return $now->between(Carbon::parse($break->start), Carbon::parse($break->end));
                });

                if ($activeBreak) {
                    // Optionally mark it as the current break
                    $activeBreak->is_current = true;
                }
            }

            // === Fetch Holidays (within session year) ===
            $holidays = Holiday::whereBetween('date', [$session_year->start_date, $session_year->end_date])
                ->orderBy('date', 'asc')
                ->get()
                ->map(fn($item) => (object)[
                    'date'  => $item->date,
                    'type'  => 'holiday',
                    'title' => $item->title,
                ]);

            // === Fetch Events (within session year) ===
            $events = Event::whereBetween('start_date', [$session_year->start_date, $session_year->end_date])
                ->orderBy('start_date', 'asc')
                ->get()
                ->map(fn($item) => (object)[
                    'date'  => $item->start_date,
                    'type'  => 'event',
                    'title' => $item->title,
                ]);

            // === Fetch Exams (for the teacher) ===
            $teacher = Auth::user()->teacher;
            if (!$teacher) {
                throw new \Exception('Teacher record not found for this user.');
            }

            $class_section_ids = ClassTeacher::where('class_teacher_id', $teacher->id)->pluck('class_section_id');
            $class_ids = ClassSection::whereIn('id', $class_section_ids)->pluck('class_id');

            if ($class_ids->isEmpty()) {
                throw new \Exception('No classes found for this teacher.');
            }

            $exams = ExamTimetable::whereBetween('date', [$session_year->start_date, $session_year->end_date])
                ->whereIn('class_id', $class_ids)
                ->orderBy('date', 'asc')
                ->get()
                ->map(fn($item) => (object)[
                    'date'  => $item->date,
                    'type'  => 'exam',
                    'title' => $item->exam_name ?? 'Exam',
                ]);

            // === Merge All Items ===
            $all_items = $holidays
                ->merge($semesterBreaks)
                ->merge($events)
                ->merge($exams)
                ->sortBy('date')
                ->values();

            // === Group by Month ===
            $calendar_items_by_month = $all_items->groupBy(function ($item) {
                return Carbon::parse($item->date)->format('F Y');
            });

            // === School Info ===
            $school_name    = env('APP_NAME');
            $school_address = getSettings('school_address')['school_address'] ?? null;
            $logo           = public_path('/storage/' . env('LOGO2'));

            // === Generate PDF ===
            $data = [
                'report_year'            => $session_year->name,
                'calendar_items_by_month' => $calendar_items_by_month,
                'school_name'            => $school_name,
                'school_address'         => $school_address,
                'logo'                   => $logo,
            ];

            $pdf    = Pdf::loadView('academic_calendar.academic_calendar_pdf', $data);
            $output = $pdf->output();

            return ResponseService::successResponse(
                "Academic Calendar PDF fetched successfully",
                null,
                [
                    'pdf' => base64_encode($output),
                ]
            );
        } catch (Throwable $e) {
            return ResponseService::errorResponse(
                "Error occurred while generating academic calendar PDF",
                null,
                103,
                $e
            );
        }
    }

    public function classes(Request $request)
    {
        try {
            $user = $request->user()->teacher;
            $class_section_id = $user->class_sections->pluck('class_section_id');

            //Find the class in which teacher is assigns as Class Teacher
            if ($user->class_sections) {

                $class_teacher = ClassSection::whereIn('id', $class_section_id)->with('class.medium', 'section', 'class.streams', 'class.shifts')->get();
            }

            //Find the Classes in which teacher is taking subjects
            $class_section_ids = $user->classes()->pluck('class_section_id');

            $class_sections = ClassSection::whereIn('id', $class_section_ids)->with('class.medium', 'section', 'class.streams', 'class.shifts')->get();
            $class_section = $class_sections->diff($class_teacher);
            ResponseService::successResponse('Teacher Classes Fetched Successfully.', ['class_teacher' => $class_teacher ?? (object)null, 'other' => $class_section]);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function subjects(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'nullable|numeric',
            'subject_id' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user = $request->user();
            $teacher = $user->teacher;
            $subjects = $teacher->subjects();
            if ($request->class_section_id) {
                $subjects = $subjects->where('class_section_id', $request->class_section_id);
            }

            if ($request->subject_id) {
                $subjects = $subjects->where('subject_id', $request->subject_id);
            }
            $subjects = $subjects->with('subject', 'class_section')->get();
            ResponseService::successResponse('Teacher Subject Fetched Successfully.', $subjects);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getAssignment(Request $request)
    {
        ResponseService::noPermissionThenSendJson('assignment-list');

        $validator = Validator::make($request->all(), [
            'class_section_id' => 'nullable|numeric',
            'subject_id' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $session_year_id = getSettings('session_year')['session_year'];
            $sql = Assignment::assignmentteachers()
                ->where('session_year_id', $session_year_id)
                ->with('class_section', 'file', 'subject');
            if ($request->class_section_id) {
                $sql = $sql->where('class_section_id', $request->class_section_id);
            }

            if ($request->subject_id) {
                $sql = $sql->where('subject_id', $request->subject_id);
            }
            $data = $sql->orderBy('id', 'DESC')->paginate();
            ResponseService::successResponse('Assignment Fetched Successfully.', $data);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function createAssignment(Request $request)
    {
        ResponseService::noPermissionThenSendJson('assignment-create');
        $validator = Validator::make($request->all(), [
            "class_section_id" => 'required|numeric|exists:class_sections,id',
            "subject_id" => 'required|numeric|exists:subjects,id',
            "name" => 'required|string|max:255',
            "instructions" => 'nullable|string',
            "due_date" => 'required|date_format:d-m-Y H:i',
            "points" => 'nullable|numeric|min:0',
            "resubmission" => 'nullable|boolean',
            "extra_days_for_resubmission" => 'nullable|numeric|min:0',
        ], [
            'class_section_id.exists' => 'Selected class section does not exist.',
            'subject_id.exists' => 'Selected subject does not exist.',
            'due_date.date_format' => 'Due date must be in format DD-MM-YYYY HH:MM (e.g., 07-08-2025 15:45)',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            // Verify teacher is assigned to this class section
            // $teacher_id = Auth::user()->teacher->id;
            // $isTeacherAssigned = ClassTeacher::where('class_section_id', $request->class_section_id)
            //     ->where('class_teacher_id', $teacher_id)
            //     ->exists();

            // if (!$isTeacherAssigned) {
            //     ResponseService::errorResponse('You are not assigned to this class section.', null, 403);
            // }

            // Verify subject exists and teacher has access
            $subject = Subject::find($request->subject_id);
            if (!$subject) {
                ResponseService::errorResponse('Selected subject does not exist.', null, 404);
            }

            $session_year = getSettings('session_year');
            $session_year_id = $session_year['session_year'];

            // Parse due date with proper format
            $due_date = Carbon::createFromFormat('d-m-Y H:i', $request->due_date);

            $assignment = new Assignment();
            $assignment->class_section_id = $request->class_section_id;
            $assignment->subject_id = $request->subject_id;
            $assignment->name = trim($request->name);
            $assignment->instructions = trim($request->instructions);
            $assignment->due_date = $due_date->format('Y-m-d H:i:s');
            $assignment->points = $request->points;
            $assignment->resubmission = $request->resubmission ? 1 : 0;
            $assignment->extra_days_for_resubmission = $request->resubmission ? $request->extra_days_for_resubmission : null;
            $assignment->session_year_id = $session_year_id;

            // Get class subject information
            $class_subject = ClassSubject::where('subject_id', $request->subject_id)->first();

            if (!$class_subject) {
                ResponseService::errorResponse('Subject is not assigned to any class.', null, 400);
            }

            // Get students based on subject type
            $user = [];
            if ($class_subject->type == 'Elective') {
                $student_ids = Students::where('class_section_id', $request->class_section_id)->pluck('id');

                foreach ($student_ids as $student_id) {
                    $student_subject = StudentSubject::where('student_id', $student_id)
                        ->where('subject_id', $request->subject_id)
                        ->where('session_year_id', $session_year_id)
                        ->first();

                    if ($student_subject) {
                        $user_id = Students::where('id', $student_subject->student_id)
                            ->where('class_section_id', $request->class_section_id)
                            ->pluck('user_id')
                            ->first();
                        if ($user_id) {
                            $user[] = $user_id;
                        }
                    }
                }
            } else {
                $user = Students::where('class_section_id', $request->class_section_id)
                    ->pluck('user_id')
                    ->toArray();
            }

            // Create notification
            $subject_name = Subject::select('name')->where('id', $request->subject_id)->pluck('name')->first();
            $title = 'New assignment added in ' . $subject_name;
            $body = $request->name;
            $type = "assignment";
            $image = null;
            $userinfo = null;

            $notification = new Notification();
            $notification->send_to = 3;
            $notification->title = $title;
            $notification->message = $body;
            $notification->type = $type;
            $notification->date = Carbon::now();
            $notification->is_custom = 0;
            $notification->save();

            // Create user notifications
            foreach ($user as $user_id) {
                if ($user_id) {
                    $user_notification = new UserNotification();
                    $user_notification->notification_id = $notification->id;
                    $user_notification->user_id = $user_id;
                    $user_notification->save();
                }
            }

            $assignment->save();

            // Send notifications
            if (!empty($user)) {
                sendSimpleNotification($user, $title, $body, $type, $image, $userinfo);
            }

            // Handle file uploads
            if ($request->hasFile('file')) {
                foreach ($request->file as $file_upload) {
                    $file = new File();
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('assignment', 'public');
                    $file->modal()->associate($assignment);
                    $file->save();
                }
            }
            ResponseService::successResponse('Assignment Created Successfully.');
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function updateAssignment(Request $request)
    {
        ResponseService::noPermissionThenSendJson('assignment-edit');

        $validator = Validator::make($request->all(), [
            "assignment_id" => 'required|numeric',
            "class_section_id" => 'required|numeric',
            "subject_id" => 'required|numeric',
            "name" => 'required',
            "instructions" => 'nullable',
            "due_date" => 'required|date',
            "points" => 'nullable',
            "resubmission" => 'nullable|boolean',
            "extra_days_for_resubmission" => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $session_year = getSettings('session_year');
            $session_year_id = $session_year['session_year'];

            $assignment = Assignment::find($request->assignment_id);
            $assignment->class_section_id = $request->class_section_id;
            $assignment->subject_id = $request->subject_id;
            $assignment->name = $request->name;
            $assignment->instructions = $request->instructions;
            $assignment->due_date = Carbon::parse($request->due_date)->format('Y-m-d H:i:s');;
            $assignment->points = $request->points;
            if ($request->resubmission) {
                $assignment->resubmission = 1;
                $assignment->extra_days_for_resubmission = $request->extra_days_for_resubmission;
            } else {
                $assignment->resubmission = 0;
                $assignment->extra_days_for_resubmission = null;
            }

            $assignment->session_year_id = $session_year_id;
            $subject_name = Subject::select('name')->where('id', $request->subject_id)->pluck('name')->first();
            $title = 'Update assignment in ' . $subject_name;
            $body = $request->name;
            $type = "assignment";
            $image = null;
            $userinfo = null;

            $user = Students::select('user_id')->where('class_section_id', $request->class_section_id)->get()->pluck('user_id');

            $notification = new Notification();
            $notification->send_to = 3;
            $notification->title = $title;
            $notification->message = $body;
            $notification->type = $type;
            $notification->date = Carbon::now();
            $notification->is_custom = 0;
            $notification->save();

            foreach ($user as $data) {
                $user_notification = new UserNotification();
                $user_notification->notification_id = $notification->id;
                $user_notification->user_id = $data;
                $user_notification->save();
            }

            $assignment->save();
            sendSimpleNotification($user, $title, $body, $type, $image, $userinfo);

            if ($request->hasFile('file')) {
                foreach ($request->file as $file_upload) {
                    $file = new File();
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('assignment', 'public');
                    $file->modal()->associate($assignment);
                    $file->save();
                }
            }
            ResponseService::successResponse('data_store_successfully');
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function deleteAssignment(Request $request)
    {
        ResponseService::noPermissionThenSendJson('assignment-delete');

        try {
            $assignment = Assignment::find($request->assignment_id);
            $assignment->delete();
            ResponseService::successResponse('data_delete_successfully');
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getAssignmentSubmission(Request $request)
    {
        ResponseService::noPermissionThenSendJson('assignment-submission');
        $validator = Validator::make($request->all(), [
            'assignment_id' => 'required|nullable|numeric'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sql = AssignmentSubmission::assignmentsubmissionteachers()->with('assignment.subject:id,name', 'student:id,user_id', 'student.user:first_name,last_name,id,image', 'file');
            $data = $sql->where('assignment_id', $request->assignment_id)->get();
            ResponseService::successResponse('Assignment Fetched Successfully.', $data);
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function updateAssignmentSubmission(Request $request)
    {
        ResponseService::noPermissionThenSendJson('assignment-submission');

        $validator = Validator::make($request->all(), [
            'assignment_submission_id' => 'required|numeric',
            'status' => 'required|numeric|in:1,2',
            'points' => 'nullable|numeric',
            'feedback' => 'nullable',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $assignment_submission = AssignmentSubmission::findOrFail($request->assignment_submission_id);
            $assignment_submission->feedback = $request->feedback;
            if ($request->status == 1) {
                $assignment_submission->points = $request->points;
            } else {
                $assignment_submission->points = null;
            }

            $assignment_submission->status = $request->status;
            $assignment_submission->save();

            $assignment_data = Assignment::where('id', $assignment_submission->assignment_id)->with('subject')->first();
            $user = Students::select('user_id')->where('id', $assignment_submission->student_id)->get()->pluck('user_id');
            $title = '';
            $body = '';
            if ($request->status == 2) {
                $title = "Assignment rejected";
                $body = $assignment_data->name . " rejected in " . $assignment_data->subject->name . " subject";
            }
            if ($request->status == 1) {
                $title = "Assignment accepted";
                $body = $assignment_data->name . " accepted in " . $assignment_data->subject->name . " subject";
            }
            $type = "assignment";
            $image = null;
            $userinfo = null;

            $notification = new Notification();
            $notification->send_to = 3;
            $notification->title = $title;
            $notification->message = $body;
            $notification->type = $type;
            $notification->date = Carbon::now();
            $notification->is_custom = 0;
            $notification->save();

            foreach ($user as $data) {
                $user_notification = new UserNotification();
                $user_notification->notification_id = $notification->id;
                $user_notification->user_id = $data;
                $user_notification->save();
            }

            sendSimpleNotification($user, $title, $body, $type, $image, $userinfo);
            ResponseService::successResponse('data_update_successfully');
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getLesson(Request $request)
    {
        ResponseService::noPermissionThenSendJson('lesson-list');

        $validator = Validator::make($request->all(), [
            'lesson_id' => 'nullable|numeric',
            'class_section_id' => 'nullable|numeric',
            'subject_id' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sql = Lesson::lessonteachers()->with('file')->withCount('topic');

            if ($request->lesson_id) {
                $sql = $sql->where('id', $request->lesson_id);
            }

            if ($request->class_section_id) {
                $sql = $sql->where('class_section_id', $request->class_section_id);
            }

            if ($request->subject_id) {
                $sql = $sql->where('subject_id', $request->subject_id);
            }
            $data = $sql->orderBy('id', 'DESC')->get();
            ResponseService::successResponse('Lesson Fetched Successfully.', $data);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function createLesson(Request $request)
    {
        ResponseService::noPermissionThenSendJson('lesson-create');

        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'description' => 'required',
                'class_section_id' => 'required|numeric',
                'subject_id' => 'required|numeric',

                'file' => 'nullable|array',
                'file.*.type' => 'nullable|in:1,2,3,4',
                'file.*.name' => 'required_with:file.*.type',
                'file.*.thumbnail' => 'required_if:file.*.type,2,3,4',
                'file.*.file' => 'required_if:file.*.type,1,3',
                'file.*.link' => 'required_if:file.*.type,2,4',

                //            'file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
                //            'file.*.name' => 'required_with:file.*.type',
                //            'file.*.thumbnail' => 'required_if:file.*.type,youtube_link,video_upload,other_link',
                //            'file.*.file' => 'required_if:file.*.type,file_upload,video_upload',
                //            'file.*.link' => 'required_if:file.*.type,youtube_link,other_link',
                //Regex for Youtube Link
                // 'file.*.link'=>['required_if:file.*.type,youtube_link','regex:/^(?:https?:\/\/)?(?:www\.)?(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))((?:\w|-){11})(?:&list=(\S+))?$/'],
                //Regex for Other Link
                // 'file.*.link'=>'required_if:file.*.type,other_link|url'
            ]
        );

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        $validator2 = Validator::make(
            $request->all(),
            [
                'name' => ['required', new uniqueLessonInClass($request->class_section_id, $request->subject_id)]
            ]
        );
        if ($validator2->fails()) {
            ResponseService::validationError($validator2->errors()->first());
        }
        try {
            $lesson = new Lesson();
            $lesson->name = $request->name;
            $lesson->description = $request->description;
            $lesson->class_section_id = $request->class_section_id;
            $lesson->subject_id = $request->subject_id;
            $lesson->save();

            if ($request->file) {
                foreach ($request->file as $key => $file) {
                    if ($file['type']) {
                        $lesson_file = new File();
                        $lesson_file->file_name = $file['name'];
                        $lesson_file->modal()->associate($lesson);

                        if ($file['type'] == "1") {
                            $lesson_file->type = 1;
                            $lesson_file->file_url = $file['file']->store('lessons', 'public');
                        } elseif ($file['type'] == "2") {
                            $lesson_file->type = 2;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['link'];
                        } elseif ($file['type'] == "3") {
                            $lesson_file->type = 3;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['file']->store('lessons', 'public');
                        } elseif ($file['type'] == "4") {
                            $lesson_file->type = 4;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['link'];
                        }
                        $lesson_file->save();
                    }
                }
            }
            ResponseService::successResponse('data_store_successfully');
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function updateLesson(Request $request)
    {
        ResponseService::noPermissionThenSendJson('lesson-edit');

        $validator = Validator::make(
            $request->all(),
            [
                'lesson_id' => 'required|numeric',
                'name' => 'required',
                'description' => 'required',
                'class_section_id' => 'required|numeric',
                'subject_id' => 'required|numeric',

                'edit_file' => 'nullable|array',
                'edit_file.*.id' => 'required|numeric',
                'edit_file.*.type' => 'nullable|in:1,2,3,4',
                'edit_file.*.name' => 'required_with:edit_file.*.type',
                'edit_file.*.link' => 'required_if:edit_file.*.type,2,4',

                'file' => 'nullable|array',
                'file.*.type' => 'nullable|in:1,2,3,4',
                'file.*.name' => 'required_with:file.*.type',
                'file.*.thumbnail' => 'required_if:file.*.type,2,3,4',
                'file.*.file' => 'required_if:file.*.type,1,3',
                'file.*.link' => 'required_if:file.*.type,2,4',

                //            'edit_file' => 'nullable|array',
                //            'edit_file.*.id' => 'required|numeric',
                //            'edit_file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
                //            'edit_file.*.name' => 'required_with:edit_file.*.type',
                //            'edit_file.*.link' => 'required_if:edit_file.*.type,youtube_link,other_link',
                //
                //            'file' => 'nullable|array',
                //            'file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
                //            'file.*.name' => 'required_with:file.*.type',
                //            'file.*.thumbnail' => 'required_if:file.*.type,youtube_link,video_upload,other_link',
                //            'file.*.file' => 'required_if:file.*.type,file_upload,video_upload',
                //            'file.*.link' => 'required_if:file.*.type,youtube_link,other_link',

                //Regex for Youtube Link
                // 'file.*.link'=>['required_if:file.*.type,youtube_link','regex:/^(?:https?:\/\/)?(?:www\.)?(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))((?:\w|-){11})(?:&list=(\S+))?$/'],
                //Regex for Other Link
                // 'file.*.link'=>'required_if:file.*.type,other_link|url'
            ]
        );
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        $validator2 = Validator::make(
            $request->all(),
            [
                'name' => ['required', new uniqueLessonInClass($request->class_section_id, $request->lesson_id)]
            ]
        );
        if ($validator2->fails()) {
            ResponseService::validationError($validator2->errors()->first());
        }
        try {
            $lesson = Lesson::find($request->lesson_id);
            $lesson->name = $request->name;
            $lesson->description = $request->description;
            $lesson->class_section_id = $request->class_section_id;
            $lesson->subject_id = $request->subject_id;
            $lesson->save();

            // Update the Old Files
            if ($request->edit_file) {
                foreach ($request->edit_file as $file) {
                    if ($file['type']) {
                        $lesson_file = File::find($file['id']);
                        if ($lesson_file) {
                            $lesson_file->file_name = $file['name'];

                            if ($file['type'] == "1") {
                                $lesson_file->type = 1;
                                if (!empty($file['file'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_url = $file['file']->store('lessons', 'public');
                                }
                            } elseif ($file['type'] == "2") {
                                $lesson_file->type = 2;
                                if (!empty($file['thumbnail'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                                }

                                $lesson_file->file_url = $file['link'];
                            } elseif ($file['type'] == "3") {
                                $lesson_file->type = 3;
                                if (!empty($file['file'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_url = $file['file']->store('lessons', 'public');
                                }

                                if (!empty($file['thumbnail'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                                }
                            } elseif ($file['type'] == "4") {
                                $lesson_file->type = 4;
                                if (!empty($file['thumbnail'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                                }
                                $lesson_file->file_url = $file['link'];
                            }

                            $lesson_file->save();
                        }
                    }
                }
            }

            //Add the new Files
            if ($request->file) {
                foreach ($request->file as $file) {
                    if ($file['type']) {
                        $lesson_file = new File();
                        $lesson_file->file_name = $file['name'];
                        $lesson_file->modal()->associate($lesson);

                        if ($file['type'] == "1") {
                            $lesson_file->type = 1;
                            $lesson_file->file_url = $file['file']->store('lessons', 'public');
                        } elseif ($file['type'] == "2") {
                            $lesson_file->type = 2;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['link'];
                        } elseif ($file['type'] == "3") {
                            $lesson_file->type = 3;
                            $lesson_file->file_url = $file['file']->store('lessons', 'public');
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                        } elseif ($file['type'] == "4") {
                            $lesson_file->type = 4;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['link'];
                        }
                        $lesson_file->save();
                    }
                }
            }
            ResponseService::successResponse('data_store_successfully');
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function deleteLesson(Request $request)
    {
        ResponseService::noPermissionThenSendJson('lesson-delete');

        $validator = Validator::make($request->all(), [
            'lesson_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $lesson = Lesson::lessonteachers()->where('id', $request->lesson_id)->firstOrFail();
            $lesson->delete();
            ResponseService::successResponse('data_delete_successfully');
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getTopic(Request $request)
    {
        ResponseService::noPermissionThenSendJson('topic-list');

        $validator = Validator::make($request->all(), [
            'lesson_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sql = LessonTopic::lessontopicteachers()->with('lesson.class_section', 'lesson.subject', 'file');
            $data = $sql->where('lesson_id', $request->lesson_id)->orderBy('id', 'DESC')->get();
            ResponseService::successResponse('Topic Fetched Successfully.', $data);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function createTopic(Request $request)
    {
        ResponseService::noPermissionThenSendJson('topic-create');

        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'description' => 'required',
                'class_section_id' => 'required|numeric',
                'subject_id' => 'required|numeric',
                'lesson_id' => 'required|numeric',
                'file' => 'nullable|array',
                'file.*.type' => 'nullable|in:1,2,3,4',
                'file.*.name' => 'required_with:file.*.type',
                'file.*.thumbnail' => 'required_if:file.*.type,2,3,4',
                'file.*.file' => 'required_if:file.*.type,1,3',
                'file.*.link' => 'required_if:file.*.type,2,4',
                //            'file' => 'nullable|array',
                //            'file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
                //            'file.*.name' => 'required_with:file.*.type',
                //            'file.*.thumbnail' => 'required_if:file.*.type,youtube_link,video_upload,other_link',
                //            'file.*.file' => 'required_if:file.*.type,file_upload,video_upload',
                //            'file.*.link' => 'required_if:file.*.type,youtube_link,other_link',
                //Regex for Youtube Link
                // 'file.*.link'=>['required_if:file.*.type,youtube_link','regex:/^(?:https?:\/\/)?(?:www\.)?(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))((?:\w|-){11})(?:&list=(\S+))?$/'],
                //Regex for Other Link
                // 'file.*.link'=>'required_if:file.*.type,other_link|url'
            ]
        );

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        $validator2 = Validator::make(
            $request->all(),
            [
                'name' => ['required', new uniqueTopicInLesson($request->lesson_id)]
            ]
        );
        if ($validator2->fails()) {
            ResponseService::validationError($validator2->errors()->first());
        }

        try {
            $topic = new LessonTopic();
            $topic->name = $request->name;
            $topic->description = $request->description;
            $topic->lesson_id = $request->lesson_id;
            $topic->save();

            if ($request->file) {
                foreach ($request->file as $data) {
                    if ($data['type']) {
                        $file = new File();
                        $file->file_name = $data['name'];
                        $file->modal()->associate($topic);

                        if ($data['type'] == "1") {
                            $file->type = 1;
                            $file->file_url = $data['file']->store('lessons', 'public');
                        } elseif ($data['type'] == "2") {
                            $file->type = 2;
                            $file->file_thumbnail = $data['thumbnail']->store('lessons', 'public');
                            $file->file_url = $data['link'];
                        } elseif ($data['type'] == "3") {
                            $file->type = 3;
                            $file->file_thumbnail = $data['thumbnail']->store('lessons', 'public');
                            $file->file_url = $data['file']->store('lessons', 'public');
                        } elseif ($data['type'] == "other_link") {
                            $file->type = 4;
                            $file->file_thumbnail = $data['thumbnail']->store('lessons', 'public');
                            $file->file_url = $data['link'];
                        }

                        $file->save();
                    }
                }
            }
            ResponseService::successResponse('data_store_successfully');
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function updateTopic(Request $request)
    {
        ResponseService::noPermissionThenSendJson('topic-edit');
        $validator = Validator::make(
            $request->all(),
            [
                'topic_id' => 'required|numeric',
                'name' => 'required',
                'description' => 'required',
                'class_section_id' => 'required|numeric',
                'subject_id' => 'required|numeric',
                'edit_file' => 'nullable|array',
                'edit_file.*.type' => 'nullable|in:1,2,3,4',
                'edit_file.*.name' => 'required_with:edit_file.*.type',
                'edit_file.*.link' => 'required_if:edit_file.*.type,2,',

                'file' => 'nullable|array',
                'file.*.type' => 'nullable|in:1,2,3,4',
                'file.*.name' => 'required_with:file.*.type',
                'file.*.thumbnail' => 'required_if:file.*.type,2,3,4',
                'file.*.file' => 'required_if:file.*.type,1,3',
                'file.*.link' => 'required_if:file.*.type,2,4',


                //            'edit_file' => 'nullable|array',
                //            'edit_file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
                //            'edit_file.*.name' => 'required_with:edit_file.*.type',
                //            'edit_file.*.link' => 'required_if:edit_file.*.type,youtube_link,',
                //
                //            'file' => 'nullable|array',
                //            'file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
                //            'file.*.name' => 'required_with:file.*.type',
                //            'file.*.thumbnail' => 'required_if:file.*.type,youtube_link,video_upload,other_link',
                //            'file.*.file' => 'required_if:file.*.type,file_upload,video_upload',
                //            'file.*.link' => 'required_if:file.*.type,youtube_link,other_link',
            ]
        );
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        $validator2 = Validator::make(
            $request->all(),
            [
                'name' => ['required', new uniqueTopicInLesson($request->lesson_id, $request->topic_id)],
            ]
        );
        if ($validator2->fails()) {
            ResponseService::validationError($validator2->errors()->first());
        }
        try {
            $topic = LessonTopic::find($request->topic_id);

            $topic->name = $request->name;
            $topic->description = $request->description;
            $topic->save();

            // Update the Old Files
            if ($request->edit_file) {
                foreach ($request->edit_file as $key => $file) {
                    if ($file['type']) {
                        $topic_file = File::find($file['id']);
                        $topic_file->file_name = $file['name'];

                        if ($file['type'] == "1") {
                            // Type File :- File Upload
                            $topic_file->type = 1;
                            if (!empty($file['file'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_url = $file['file']->store('lessons', 'public');
                            }
                        } elseif ($file['type'] == "2") {
                            // Type File :- Youtube Link Upload
                            $topic_file->type = 2;
                            if (!empty($file['thumbnail'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            }

                            $topic_file->file_url = $file['link'];
                        } elseif ($file['type'] == "3") {
                            // Type File :- Vedio Upload
                            $topic_file->type = 3;
                            if (!empty($file['file'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_url = $file['file']->store('lessons', 'public');
                            }

                            if (!empty($file['thumbnail'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            }
                        } elseif ($file['type'] == "4") {
                            $topic_file->type = 4;
                            if (!empty($file['thumbnail'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            }
                            $topic_file->file_url = $file['link'];
                        }

                        $topic_file->save();
                    }
                }
            }

            //Add the new Files
            if ($request->file) {
                foreach ($request->file as $file) {
                    $topic_file = new File();
                    $topic_file->file_name = $file['name'];
                    $topic_file->modal()->associate($topic);

                    if ($file['type'] == "1") {
                        $topic_file->type = 1;
                        $topic_file->file_url = $file['file']->store('lessons', 'public');
                    } elseif ($file['type'] == "2") {
                        $topic_file->type = 2;
                        $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                        $topic_file->file_url = $file['link'];
                    } elseif ($file['type'] == "3") {
                        $topic_file->type = 3;
                        $topic_file->file_url = $file['file']->store('lessons', 'public');
                        $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                    } elseif ($file['type'] == "4") {
                        $topic_file->type = 4;
                        $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                        $topic_file->file_url = $file['link'];
                    }
                    $topic_file->save();
                }
            }
            ResponseService::successResponse('data_store_successfully');
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function deleteTopic(Request $request)
    {
        ResponseService::noPermissionThenSendJson('topic-delete');


        try {
            $topic = LessonTopic::LessonTopicTeachers()->findOrFail($request->topic_id);
            $topic->delete();
            ResponseService::successResponse('data_delete_successfully');
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function updateFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $file = File::find($request->file_id);
            $file->file_name = $request->name;


            if ($file->type == "1") {
                // Type File :- File Upload

                if (!empty($request->file)) {
                    if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                        Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                    }

                    if ($file->modal_type == "App\Models\Lesson") {

                        $file->file_url = $request->file->store('lessons', 'public');
                    } else if ($file->modal_type == "App\Models\LessonTopic") {

                        $file->file_url = $request->file->store('topics', 'public');
                    } else {

                        $file->file_url = $request->file->store('other', 'public');
                    }
                }
            } elseif ($file->type == "2") {
                // Type File :- Youtube Link Upload

                if (!empty($request->thumbnail)) {
                    if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                        Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                    }

                    if ($file->modal_type == "App\Models\Lesson") {

                        $file->file_thumbnail = $request->thumbnail->store('lessons', 'public');
                    } else if ($file->modal_type == "App\Models\LessonTopic") {

                        $file->file_thumbnail = $request->thumbnail->store('topics', 'public');
                    } else {

                        $file->file_thumbnail = $request->thumbnail->store('other', 'public');
                    }
                }
                $file->file_url = $request->link;
            } elseif ($file->type == "3") {
                // Type File :- Vedio Upload

                if (!empty($request->file)) {
                    if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                        Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                    }

                    if ($file->modal_type == "App\Models\Lesson") {

                        $file->file_url = $request->file->store('lessons', 'public');
                    } else if ($file->modal_type == "App\Models\LessonTopic") {

                        $file->file_url = $request->file->store('topics', 'public');
                    } else {

                        $file->file_url = $request->file->store('other', 'public');
                    }
                }

                if (!empty($request->thumbnail)) {
                    if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                        Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                    }
                    if ($file->modal_type == "App\Models\Lesson") {

                        $file->file_thumbnail = $request->thumbnail->store('lessons', 'public');
                    } else if ($file->modal_type == "App\Models\LessonTopic") {

                        $file->file_thumbnail = $request->thumbnail->store('topics', 'public');
                    } else {

                        $file->file_thumbnail = $request->thumbnail->store('other', 'public');
                    }
                }
            }
            $file->save();
            ResponseService::successResponse('data_store_successfully', $file);
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function deleteFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $file = File::findOrFail($request->file_id);
            $file->delete();
            ResponseService::successResponse('data_delete_successfully');
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getAnnouncement(Request $request)
    {
        ResponseService::noPermissionThenSendJson('announcement-list');

        $validator = Validator::make($request->all(), [
            'class_section_id' => 'nullable|numeric',
            'subject_id' => 'nullable|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher = Auth::user()->teacher;
            $subject_teacher_ids = SubjectTeacher::where('teacher_id', $teacher->id);
            if ($request->class_section_id) {
                $subject_teacher_ids = $subject_teacher_ids->where('class_section_id', $request->class_section_id);
            }
            if ($request->subject_id) {
                $subject_teacher_ids = $subject_teacher_ids->where('subject_id', $request->subject_id);
            }
            $subject_teacher_ids = $subject_teacher_ids->get()->pluck('id');
            $sql = Announcement::with('table.subject', 'file')->where('table_type', 'App\Models\SubjectTeacher')->whereIn('table_id', $subject_teacher_ids);

            $data = $sql->orderBy('id', 'DESC')->paginate();
            ResponseService::successResponse('Announcement Fetched Successfully.', $data);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function sendAnnouncement(Request $request)
    {
        ResponseService::noPermissionThenSendJson('announcement-create');

        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required|numeric|exists:class_sections,id',
            'subject_id' => 'required|numeric|exists:subjects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'nullable|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif'
        ], [
            'class_section_id.exists' => 'Selected class section does not exist.',
            'subject_id.exists' => 'Selected subject does not exist.',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            // Verify teacher is assigned to this class section and subject
            $teacher_id = Auth::user()->teacher->id;
            $isTeacherAssigned = SubjectTeacher::where('teacher_id', $teacher_id)
                ->where('class_section_id', $request->class_section_id)
                ->where('subject_id', $request->subject_id)
                ->exists();

            if (!$isTeacherAssigned) {
                ResponseService::errorResponse('You are not assigned to this class section and subject combination.', null, 403);
            }

            // Verify class section and subject exist
            $class_section = ClassSection::find($request->class_section_id);
            if (!$class_section) {
                ResponseService::errorResponse('Selected class section does not exist.', null, 404);
            }

            $subject = Subject::find($request->subject_id);
            if (!$subject) {
                ResponseService::errorResponse('Selected subject does not exist.', null, 404);
            }

            $data = getSettings('session_year');
            $announcement = new Announcement();
            $announcement->title = trim($request->title);
            $announcement->description = trim($request->description);
            $announcement->session_year_id = $data['session_year'];

            // Get subject teacher record
            $subject_teacher = SubjectTeacher::where([
                'teacher_id' => $teacher_id,
                'class_section_id' => $request->class_section_id,
                'subject_id' => $request->subject_id
            ])->with('subject')->first();

            if (!$subject_teacher) {
                ResponseService::errorResponse('Subject teacher record not found.', null, 404);
            }

            $announcement->table()->associate($subject_teacher);

            // Get students in the class section
            $user = Students::select('user_id')
                ->where('class_section_id', $request->class_section_id)
                ->get()
                ->pluck('user_id')
                ->filter() // Remove null values
                ->toArray();

            $title = 'New announcement in ' . $subject_teacher->subject->name;
            $body = $request->title;
            $image = null;
            $userinfo = null;

            $announcement->save();

            // Send notifications only if there are students
            if (!empty($user)) {
                sendSimpleNotification($user, $title, $body, 'class_section', $image, $userinfo);
            }

            // Handle file uploads
            if ($request->hasFile('file')) {
                foreach ($request->file as $file_upload) {
                    $file = new File();
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('announcement', 'public');
                    $file->modal()->associate($announcement);
                    $file->save();
                }
            }
            ResponseService::successResponse('data_store_successfully');
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function updateAnnouncement(Request $request)
    {
        ResponseService::noPermissionThenSendJson('announcement-edit');

        $validator = Validator::make($request->all(), [
            'announcement_id' => 'required|numeric',
            'class_section_id' => 'required|numeric',
            'subject_id' => 'required|numeric',
            'title' => 'required'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher_id = Auth::user()->teacher->id;
            $announcement = Announcement::findOrFail($request->announcement_id);
            $announcement->title = $request->title;
            $announcement->description = $request->description;

            $subject_teacher = SubjectTeacher::where(['teacher_id' => $teacher_id, 'class_section_id' => $request->class_section_id, 'subject_id' => $request->subject_id])->with('subject')->firstOrFail();
            $announcement->table()->associate($subject_teacher);
            $user = Students::select('user_id')->where('class_section_id', $request->class_section_id)->get()->pluck('user_id');

            $title = 'Update announcement in ' . $subject_teacher->subject->name;
            $body = $request->title;
            $image = null;
            $userinfo = null;

            $announcement->save();
            sendSimpleNotification($user, $title, $body, 'class_section', $image, $userinfo);
            if ($request->hasFile('file')) {
                foreach ($request->file as $file_upload) {
                    $file = new File();
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('announcement', 'public');
                    $file->modal()->associate($announcement);
                    $file->save();
                }
            }
            ResponseService::successResponse('data_update_successfully');
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function deleteAnnouncement(Request $request)
    {
        ResponseService::noPermissionThenSendJson('announcement-delete');

        $validator = Validator::make($request->all(), [
            'announcement_id' => 'required|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $announcement = Announcement::findorFail($request->announcement_id);
            $announcement->delete();
            ResponseService::successResponse('data_delete_successfully');
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getAttendance(Request $request)
    {
        ResponseService::noPermissionThenSendJson('attendance-list');

        $class_section_id = $request->class_section_id;
        $attendance_type = $request->type;
        $date = date('Y-m-d', strtotime($request->date));

        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
            'date' => 'required|date',
            'type' => 'in:0,1',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $on_leave_student_ids = [];

            // $current_date = Carbon::now()->toDateString();

            $students = Students::where('class_section_id', $class_section_id)->pluck('user_id');

            $on_leave_student_ids = Leave::with('leave_detail')->where('status', 1)
                ->whereIn('user_id', $students)
                ->whereHas('leave_detail', function ($query) use ($date) {
                    $query->whereDate('date',  $date);
                })
                ->pluck('user_id')
                ->map(function ($user_id) {
                    return Students::where('user_id', $user_id)->pluck('id')->first();
                })
                ->filter()
                ->toArray();

            $sql = Attendance::where('class_section_id', $class_section_id)->where('date', $date);
            if (isset($attendance_type) && $attendance_type != '') {
                $sql->where('type', $attendance_type);
            }
            $data = $sql->get();
            $holiday = Holiday::where('date', $date)->get();
            if ($holiday->count()) {
                ResponseService::successResponse('data_update_successfully', $data, ['is_holiday' => true, 'holiday' => $holiday]);
            } else {
                if ($data->count()) {
                    ResponseService::successResponse('Data Fetched Successfully', $data, ['is_holiday' => false, 'on_leave_student_ids' => $on_leave_student_ids]);
                } else {
                    ResponseService::successResponse('Attendance not recorded', $data, ['is_holiday' => false, 'holiday' => ($holiday->count() == 0) ?  null : $holiday, 'on_leave_student_ids' => $on_leave_student_ids]);
                }
            }
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function submitAttendance(Request $request)
    {
        ResponseService::noAnyPermissionThenSendJson(['attendance-create', 'attendance-edit']);

        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
            // 'student_id' => 'required',
            'attendance.*.student_id' => 'required',
            'attendance.*.type' => 'required|in:0,1',
            'date' => 'required|date',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $session_year = getSettings('session_year');
            $session_year_id = $session_year['session_year'];
            $class_section_id = $request->class_section_id;
            $date = date('Y-m-d', strtotime($request->date));
            $getid = Attendance::where(['date' => $date, 'class_section_id' => $class_section_id])->get();
            foreach ($request->attendance as $attendance) {
                $studentAttendance = $getid->first(function ($query) use ($attendance) {
                    return $query->student_id == $attendance['student_id'];
                });

                if ($request->holiday != '' && $request->holiday == 3) {
                    $type = $request->holiday;
                } else {
                    $type = $attendance['type'];
                    if ($type == 0) {
                        $absent_student_ids[] = $attendance['student_id'];
                    }
                }
                $attendanceData[] = [
                    'id' => $studentAttendance->id ?? null,
                    'class_section_id' => $class_section_id,
                    'student_id' => $attendance['student_id'],
                    'session_year_id' => $session_year_id,
                    'date' => $date,
                    'type' => $type,
                    'status' => 1,
                ];
            }
            Attendance::upsert($attendanceData, ['id'], ['class_section_id', 'student_id', 'session_year_id', 'date', 'type', 'status']);
            //Send Notification to parents
            if (!empty($absent_student_ids)) {
                $student = Students::with('user')->whereIn('id', $absent_student_ids)->get();
                foreach ($student as $student) {
                    $user = Parents::where('id', $student->father_id)->orwhere('id', $student->mother_id)->pluck('user_id');
                    $title = 'Attendance Alert';
                    $body = $student->user->first_name . ' ' . $student->user->last_name . ' ' . 'is Absent on' . ' ' . date('d-m-Y', strtotime($date));;
                    $type = 'attendance';
                    $image = null;
                    $userinfo = null;

                    $notification = new Notification();
                    $notification->send_to = 3;
                    $notification->title = $title;
                    $notification->message = $body;
                    $notification->type = $type;
                    $notification->date = Carbon::now();
                    $notification->is_custom = 0;
                    $notification->save();

                    // Prepare data for batch insert
                    $userNotificationData = [];
                    foreach ($user as $data) {
                        $userNotificationData[] = [
                            'notification_id' => $notification->id,
                            'user_id' => $data,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];

                        sendSimpleNotification($user, $title, $body, $type, $image, $userinfo);
                    }
                }
                // Batch insert all user notifications
                if (!empty($userNotificationData)) {
                    UserNotification::insert($userNotificationData);
                }
            }
            ResponseService::successResponse('data_store_successfully');
        } catch (Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getStudentList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required|numeric',
            'subject_id' => 'nullable',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $user = Auth::user()->teacher;
            $class_section_id = $request->class_section_id;

            // Verify that the teacher is assigned to this specific class section
            $isTeacherAssigned = ClassTeacher::where('class_section_id', $class_section_id)
                ->where('class_teacher_id', $user->id)
                ->exists();

            if (!$isTeacherAssigned) {
                ResponseService::errorResponse('You are not assigned to this class section', null, 403);
            }

            $sql = Students::with('user:id,first_name,last_name,image,gender,dob,current_address,permanent_address', 'class_section')
                ->where('class_section_id', $class_section_id);

            $data = $sql->orderBy('roll_number')->get();

            if (isset($request->subject_id)) {
                $class_id = ClassSection::where('id', $class_section_id)->pluck('class_id');
                $class_subject = ClassSubject::where('subject_id', $request->subject_id)->where('class_id', $class_id)->first();

                if ($class_subject->type == "Elective") {
                    foreach ($data as $student) {
                        $student_id[] = $student->id;
                    }
                    $student_subject = StudentSubject::whereIn('student_id', $student_id)->where('subject_id', $request->subject_id)->where('class_section_id', $class_section_id)->pluck('student_id');

                    if ($student_subject) {
                        $sql = Students::with('user:id,first_name,last_name,image,gender,dob,current_address,permanent_address', 'class_section')->whereIn('id', $student_subject);
                        $data = $sql->orderBy('id')->get();
                    }
                } else {
                    $sql = Students::with('user:id,first_name,last_name,image,gender,dob,current_address,permanent_address', 'class_section')->where('class_section_id', $class_section_id);
                    $data = $sql->orderBy('id')->get();
                }
            }
            ResponseService::successResponse('Student Details Fetched Successfully', $data);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getStudentDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $session_year = getSettings('session_year');
            $session_year_id = $session_year['session_year'];

            $student_data_ids = Students::select('user_id', 'class_section_id', 'father_id', 'mother_id', 'guardian_id')->where('id', $request->student_id)->get();
            $student_total_present = Attendance::where('student_id', $request->student_id)->where('session_year_id', $session_year_id)->where('type', 1)->count();
            $student_total_absent = Attendance::where('student_id', $request->student_id)->where('session_year_id', $session_year_id)->where('type', 0)->count();

            $today_date_string = Carbon::now();
            $today_date_string->toDateTimeString();
            $today_date = date('Y-m-d', strtotime($today_date_string->toDateString()));

            $student_today_attendance = Attendance::where('student_id', $request->student_id)->where('date', $today_date)->get();
            if ($student_today_attendance->count()) {
                foreach ($student_today_attendance as $student_attendance) {
                    if ($student_attendance['type'] == 1) {
                        $today_attendance = 'Present';
                    } else {
                        $today_attendance = 'Absent';
                    }
                }
            } else {
                $today_attendance = 'Not Taken';
            }
            foreach ($student_data_ids as $student_data_ids) {
                $father_data = Parents::where('id', $student_data_ids['father_id'])->get();
                $mother_data = Parents::where('id', $student_data_ids['mother_id'])->get();
                if ($student_data_ids['guardian_id'] != 0) {
                    $guardian_data = Parents::where('id', $student_data_ids['guardian_id'])->get();

                    ResponseService::successResponse('Student Details Fetched Successfully', null, [
                        'gurdian_data' => $guardian_data,
                        'father_data' => $father_data,
                        'mother_data' => $mother_data,
                        'total_present' => $student_total_present,
                        'total_absent' => $student_total_absent,
                        'today_attendance' => $today_attendance,
                    ]);
                } else {
                    ResponseService::successResponse('Student Details Fetched Successfully', null, [
                        'father_data' => $father_data,
                        'mother_data' => $mother_data,
                        'total_present' => $student_total_present,
                        'total_absent' => $student_total_absent,
                        'today_attendance' => $today_attendance,
                    ]);
                }
            }
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getTeacherTimetable(Request $request)
    {
        try {
            $teacher = $request->user()->teacher;
            $subject_id = SubjectTeacher::where('teacher_id', $teacher->id)->pluck('id');
            $timetable = Timetable::whereIn('subject_teacher_id', $subject_id)->with('class_section', 'subject')->get();
            ResponseService::successResponse('Timetable Fetched Successfully', $timetable);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function submitExamMarksBySubjects(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required|numeric',
            'exam_id' => 'required|numeric',
            'subject_id' => 'required|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $exam_published = Exam::where(['id' => $request->exam_id, 'publish' => 1])->first();
            if (isset($exam_published)) {
                ResponseService::errorResponse('exam_published', null, 400);
            }

            $teacher_id = Auth::user()->teacher->id;
            $class_id = ClassSection::where('id', $request->class_section_id)->pluck('class_id');


            //check exam status
            $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where(['exam_id' => $request->exam_id, 'class_id' => $class_id])->first();
            $starting_date = $starting_date_db['min(date)'];
            $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where(['exam_id' => $request->exam_id, 'class_id' => $class_id])->first();
            $ending_date = $ending_date_db['max(date)'];
            $currentTime = Carbon::now();
            $current_date = date($currentTime->toDateString());
            if ($current_date >= $starting_date && $current_date <= $ending_date) {
                $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
            } elseif ($current_date < $starting_date) {
                $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
            } else {
                $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
            }
            if ($exam_status != 2) {
                ResponseService::errorResponse('exam_not_completed_yet', null, 400);
            } else {
                $grades = Grade::orderBy('ending_range', 'desc')->get();
                $exam_timetable = ExamTimetable::where('exam_id', $request->exam_id)->where('subject_id', $request->subject_id)->firstOrFail();
                foreach ($request->marks_data as $marks) {
                    $passing_marks = $exam_timetable->passing_marks;
                    if ($marks['obtained_marks'] >= $passing_marks) {
                        $status = 1;
                    } else {
                        $status = 0;
                    }
                    $marks_percentage = ($marks['obtained_marks'] / $exam_timetable['total_marks']) * 100;

                    $exam_grade = findExamGrade($marks_percentage);
                    if ($exam_grade == null) {
                        ResponseService::errorResponse('grades_data_does_not_exists', null, 400);
                    }

                    $exam_marks = ExamMarks::where(['exam_timetable_id' => $exam_timetable->id, 'subject_id' => $request->subject_id, 'student_id' => $marks['student_id']])->first();
                    if ($exam_marks) {
                        $exam_marks_db = ExamMarks::find($exam_marks->id);
                        $exam_marks_db->obtained_marks = $marks['obtained_marks'];
                        $exam_marks_db->passing_status = $status;
                        $exam_marks_db->grade = $exam_grade;
                        $exam_marks_db->save();
                        ResponseService::successResponse('data_update_successfully');
                    } else {
                        $exam_result_marks[] = array(
                            'exam_timetable_id' => $exam_timetable->id,
                            'student_id' => $marks['student_id'],
                            'subject_id' => $request->subject_id,
                            'obtained_marks' => $marks['obtained_marks'],
                            'passing_status' => $status,
                            'session_year_id' => $exam_timetable->session_year_id,
                            'grade' => $exam_grade,
                        );
                    }
                }
                if (isset($exam_result_marks)) {
                    ExamMarks::insert($exam_result_marks);
                    ResponseService::successResponse('data_store_successfully');
                }
            }
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function submitExamMarksByStudent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
            'exam_id' => 'required|numeric',
            'student_id' => 'required|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $exam_published = Exam::where(['id' => $request->exam_id, 'publish' => 1])->first();
            if (isset($exam_published)) {
                ResponseService::errorResponse('exam_published', null, 400);
            }

            $teacher_id = Auth::user()->teacher->id;
            // $class_section_id = Students::where('id',$request->student_id)->pluck('class_section_id');
            $class_id = ClassSection::where('id', $request->class_section_id)->pluck('class_id');

            //exam status
            $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where(['exam_id' => $request->exam_id, 'class_id' => $class_id])->first();
            $starting_date = $starting_date_db['min(date)'];
            $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where(['exam_id' => $request->exam_id, 'class_id' => $class_id])->first();
            $ending_date = $ending_date_db['max(date)'];
            $currentTime = Carbon::now();
            $current_date = date($currentTime->toDateString());
            if ($current_date >= $starting_date && $current_date <= $ending_date) {
                $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
            } elseif ($current_date < $starting_date) {
                $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
            } else {
                $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
            }

            if ($exam_status != 2) {
                ResponseService::errorResponse('exam_not_completed_yet', null, 400);
            } else {
                $grades = Grade::orderBy('ending_range', 'desc')->get();

                foreach ($request->marks_data as $marks) {
                    $exam_timetable = ExamTimetable::where(['exam_id' => $request->exam_id, 'subject_id' => $marks['subject_id']])->firstOrFail();
                    $passing_marks = $exam_timetable->passing_marks;
                    if ($marks['obtained_marks'] >= $passing_marks) {
                        $status = 1;
                    } else {
                        $status = 0;
                    }
                    $marks_percentage = ($marks['obtained_marks'] / $exam_timetable->total_marks) * 100;

                    $exam_grade = findExamGrade($marks_percentage);
                    if ($exam_grade == null) {
                        ResponseService::errorResponse('grades_data_does_not_exists', null, 400);
                    }

                    $exam_marks = ExamMarks::where(['exam_timetable_id' => $exam_timetable->id, 'student_id' => $request->student_id, 'subject_id' => $marks['subject_id']])->first();
                    if ($exam_marks) {
                        $exam_marks_db = ExamMarks::find($exam_marks->id);
                        $exam_marks_db->obtained_marks = $marks['obtained_marks'];
                        $exam_marks_db->passing_status = $status;
                        $exam_marks_db->grade = $exam_grade;
                        $exam_marks_db->save();
                        ResponseService::successResponse('data_update_successfully');
                    } else {
                        $exam_result_marks[] = array(
                            'exam_timetable_id' => $exam_timetable->id,
                            'student_id' => $request->student_id,
                            'subject_id' => $marks['subject_id'],
                            'obtained_marks' => $marks['obtained_marks'],
                            'passing_status' => $status,
                            'session_year_id' => $exam_timetable->session_year_id,
                            'grade' => $exam_grade,
                        );
                    }
                }
                if (isset($exam_result_marks)) {
                    ExamMarks::insert($exam_result_marks);
                    ResponseService::successResponse('data_store_successfully');
                }
            }
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function GetStudentExamResult(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|nullable'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            // $teacher_id = Auth::user()->teacher->id;
            $class_section_id = Students::where('id', $request->student_id)->pluck('class_section_id');

            $class_data = ClassSection::where('id', $class_section_id)->with('class.medium', 'section')->get()->first();

            $exam_marks_db = ExamClass::with(['exam.timetable' => function ($q) use ($request, $class_data) {
                $q->where('class_id', $class_data->class_id)->with(['exam_marks' => function ($q) use ($request) {
                    $q->where('student_id', $request->student_id);
                }])->with('subject:id,name,type,image,code');
            }])->with(['exam.results' => function ($q) use ($request) {
                $q->where('student_id', $request->student_id)->with(['student' => function ($q) {
                    $q->select('id', 'user_id', 'roll_number')->with('user:id,first_name,last_name');
                }])->with('session_year:id,name');
            }])->where('class_id', $class_data->class_id)->get();

            if (sizeof($exam_marks_db)) {
                foreach ($exam_marks_db as $data_db) {
                    $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where(['exam_id' => $data_db->exam_id, 'class_id' => $class_data->class_id])->first();
                    $starting_date = $starting_date_db['min(date)'];
                    $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where(['exam_id' => $data_db->exam_id, 'class_id' => $class_data->class_id])->first();
                    $ending_date = $ending_date_db['max(date)'];
                    $currentTime = Carbon::now();
                    $current_date = date($currentTime->toDateString());
                    if ($current_date >= $starting_date && $current_date <= $ending_date) {
                        $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
                    } elseif ($current_date < $starting_date) {
                        $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
                    } else {
                        $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
                    }

                    // check wheather exam is completed or not
                    if ($exam_status == 2) {
                        $marks_array = array();

                        // check wheather timetable exists or not
                        if (sizeof($data_db->exam->timetable)) {
                            foreach ($data_db->exam->timetable as $timetable_db) {
                                $total_marks = $timetable_db->total_marks;
                                $exam_marks = array();
                                if (sizeof($timetable_db->exam_marks)) {
                                    foreach ($timetable_db->exam_marks as $marks_data) {
                                        $exam_marks = array(
                                            'marks_id' => $marks_data->id,
                                            'subject_name' => $marks_data->subject->name,
                                            'subject_type' => $marks_data->subject->type,
                                            'total_marks' => $total_marks,
                                            'obtained_marks' => $marks_data->obtained_marks,
                                            'grade' => $marks_data->grade,
                                        );
                                    }
                                } else {
                                    $exam_marks = (object)[];
                                }
                                if ($exam_marks != (object)[]) {
                                    $marks_array[] = array(
                                        'subject_id' => $timetable_db->subject->id,
                                        'subject_name' => $timetable_db->subject->name,
                                        'subject_type' => $timetable_db->subject->type,
                                        'total_marks' => $total_marks,
                                        'subject_code' => $timetable_db->subject->code,
                                        'marks' => $exam_marks
                                    );
                                }
                            }

                            $exam_result = array();
                            if (sizeof($data_db->exam->results)) {
                                foreach ($data_db->exam->results as $result_data) {
                                    $exam_result = array(
                                        'result_id' => $result_data->id,
                                        'exam_id' => $result_data->exam_id,
                                        'exam_name' => $data_db->exam->name,
                                        'class_name' => $class_data->class->name . '-' . $class_data->section->name . ' ' . $class_data->class->medium->name,
                                        'student_name' => $result_data->student->user->first_name . ' ' . $result_data->student->user->last_name,
                                        'exam_date' => $starting_date,
                                        'total_marks' => $result_data->total_marks,
                                        'obtained_marks' => $result_data->obtained_marks,
                                        'percentage' => $result_data->percentage,
                                        'grade' => $result_data->grade,
                                        'session_year' => $result_data->session_year->name,
                                    );
                                }
                            } else {
                                $exam_result = (object)[];
                            }
                            if ($marks_array != null && $exam_result != null) {
                                $data[] = array(
                                    'exam_id' => $data_db->exam_id,
                                    'exam_name' => $data_db->exam->name,
                                    'exam_date' => $starting_date,
                                    'marks_data' => $marks_array,
                                    'result' => $exam_result
                                );
                            }
                        }
                    }
                }
                ResponseService::successResponse('Exam Marks Fetched Successfully', $data ?? []);
            } else {
                ResponseService::successResponse('Exam Marks Fetched Successfully', []);
            }
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function GetStudentExamMarks(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|nullable'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            // $teacher_id = Auth::user()->teacher->id;
            $class_section_id = Students::where('id', $request->student_id)->pluck('class_section_id');

            $class_data = ClassSection::where('id', $class_section_id)->with('class.medium', 'section')->get()->first();

            $exam_marks_db = ExamClass::with(['exam.timetable' => function ($q) use ($request, $class_data) {
                $q->where('class_id', $class_data->class_id)->with(['exam_marks' => function ($q) use ($request) {
                    $q->where('student_id', $request->student_id);
                }])->with('subject:id,name,type,image');
            }])->where('class_id', $class_data->class_id)->get();

            if (sizeof($exam_marks_db)) {
                foreach ($exam_marks_db as $data_db) {
                    $marks_array = array();
                    foreach ($data_db->exam->timetable as $marks_db) {
                        $exam_marks = array();
                        if (sizeof($marks_db->exam_marks)) {
                            foreach ($marks_db->exam_marks as $marks_data) {
                                $exam_marks = array(
                                    'marks_id' => $marks_data->id,
                                    'subject_name' => $marks_data->subject->name,
                                    'subject_type' => $marks_data->subject->type,
                                    'total_marks' => $marks_data->timetable->total_marks,
                                    'obtained_marks' => $marks_data->obtained_marks,
                                    'grade' => $marks_data->grade,
                                );
                            }
                        } else {
                            $exam_marks = [];
                        }
                        if ($exam_marks != []) {
                            $marks_array[] = array(
                                'subject_id' => $marks_db->subject->id,
                                'subject_name' => $marks_db->subject->name,
                                'marks' => $exam_marks
                            );
                        }
                    }
                    $data[] = array(
                        'exam_id' => $data_db->exam_id,
                        'exam_name' => $marks_db->exam->name,
                        'marks_data' => $marks_array
                    );
                }
                ResponseService::successResponse('Exam Marks Fetched Successfully', $data);
            } else {
                ResponseService::successResponse('Exam Marks Fetched Successfully', []);
            }
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getExamList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'in:0,1,2,3',
            'publish' => 'in:0,1',
            'class_section_id' => 'nullable',
            'get_timetable' => 'nullable'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $teacher = Auth::user()->teacher;

            if (isset($request->class_section_id)) {

                $class_ids = ClassSection::with('class')->where('id', $request->class_section_id)->pluck('class_id');
                // $class_section = ClassSection::with('class', 'section', 'class.medium', 'class.streams')->where('id', $request->class_section_id)->first();
            } else {

                $class_section_ids = ClassTeacher::where('class_teacher_id', $teacher->id)->pluck('class_section_id');
                $class_ids = ClassSection::with('class')->whereIn('id', $class_section_ids)->pluck('class_id');
                // $class_sections = ClassSection::with('class', 'section', 'class.medium', 'class.streams')->whereIn('id', $class_section_ids)->get();
            }

            $sql = ExamClass::with('exam.session_year:id,name', 'exam.timetable.subject', 'class', 'class.medium', 'class.streams')->whereIn('class_id', $class_ids);

            if (isset($request->publish)) {
                $publish = $request->publish;
                $sql->whereHas('exam', function ($q) use ($publish) {
                    $q->where('publish', $publish);
                });
            }
            $exam_data_db = $sql->get();

            // dd($exam_data_db->toArray());
            foreach ($exam_data_db as $data) {

                // date status
                $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where('exam_id', $data->exam_id)->whereIn('class_id', $class_ids)->first();
                $starting_date = $starting_date_db['min(date)'];

                $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where('exam_id', $data->exam_id)->whereIn('class_id', $class_ids)->first();
                $ending_date = $ending_date_db['max(date)'];

                $currentTime = Carbon::now();
                $current_date = date($currentTime->toDateString());
                if ($current_date >= $starting_date && $current_date <= $ending_date) {
                    $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
                } elseif ($current_date < $starting_date) {
                    $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
                } else {
                    $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
                }

                // $request->status  =  0 :- all exams , 1 :- Upcoming , 2 :- On Going , 3 :- Completed

                if (isset($request->status)) {
                    if ($request->status == 0) {
                        if ($request->get_timetable == 1) {
                            $exam_data[] = array(
                                'id' => $data->exam->id,
                                'name' => $data->exam->name,
                                'description' => $data->exam->description,
                                'publish' => $data->exam->publish,
                                'session_year' => $data->exam->session_year->name,
                                'exam_starting_date' => $starting_date,
                                'exam_ending_date' => $ending_date,
                                'exam_status' => $exam_status,
                                'exam_timetable' => $data->exam->timetable,
                                'class_id' => $data->class_id,
                                'class_name' => $data->class->name . '-' . $data->class->medium->name,
                                'class_streams' => $data->class->streams->name ?? null,
                            );
                        } else {
                            $exam_data[] = array(
                                'id' => $data->exam->id,
                                'name' => $data->exam->name,
                                'description' => $data->exam->description,
                                'publish' => $data->exam->publish,
                                'session_year' => $data->exam->session_year->name,
                                'exam_starting_date' => $starting_date,
                                'exam_ending_date' => $ending_date,
                                'exam_status' => $exam_status,
                                'class_id' => $data->class_id,
                                'class_name' => $data->class->name . '-' . $data->class->medium->name,
                                'class_streams' => $data->class->streams->name ?? null,
                            );
                        }
                    } else if ($request->status == 1) {
                        if ($exam_status == 0) {
                            if ($request->get_timetable == 1) {
                                $exam_data[] = array(
                                    'id' => $data->exam->id,
                                    'name' => $data->exam->name,
                                    'description' => $data->exam->description,
                                    'publish' => $data->exam->publish,
                                    'session_year' => $data->exam->session_year->name,
                                    'exam_starting_date' => $starting_date,
                                    'exam_ending_date' => $ending_date,
                                    'exam_status' => $exam_status,
                                    'exam_timetable' => $data->exam->timetable,
                                    'class_id' => $data->class_id,
                                    'class_name' => $data->class->name . '-' . $data->class->medium->name,
                                    'class_streams' => $data->class->streams->name ?? null,
                                );
                            } else {
                                $exam_data[] = array(
                                    'id' => $data->exam->id,
                                    'name' => $data->exam->name,
                                    'description' => $data->exam->description,
                                    'publish' => $data->exam->publish,
                                    'session_year' => $data->exam->session_year->name,
                                    'exam_starting_date' => $starting_date,
                                    'exam_ending_date' => $ending_date,
                                    'exam_status' => $exam_status,
                                    'class_id' => $data->class_id,
                                    'class_name' => $data->class->name . '-' . $data->class->medium->name,
                                    'class_streams' => $data->class->streams->name ?? null,
                                );
                            }
                        }
                    } else if ($request->status == 2) {
                        if ($exam_status == 1) {
                            if ($request->get_timetable == 1) {
                                $exam_data[] = array(
                                    'id' => $data->exam->id,
                                    'name' => $data->exam->name,
                                    'description' => $data->exam->description,
                                    'publish' => $data->exam->publish,
                                    'session_year' => $data->exam->session_year->name,
                                    'exam_starting_date' => $starting_date,
                                    'exam_ending_date' => $ending_date,
                                    'exam_status' => $exam_status,
                                    'exam_timetable' => $data->exam->timetable,
                                    'class_id' => $data->class_id,
                                    'class_name' => $data->class->name . '-' . $data->class->medium->name,
                                    'class_streams' => $data->class->streams->name ?? null,
                                );
                            } else {
                                $exam_data[] = array(
                                    'id' => $data->exam->id,
                                    'name' => $data->exam->name,
                                    'description' => $data->exam->description,
                                    'publish' => $data->exam->publish,
                                    'session_year' => $data->exam->session_year->name,
                                    'exam_starting_date' => $starting_date,
                                    'exam_ending_date' => $ending_date,
                                    'exam_status' => $exam_status,
                                    'class_id' => $data->class_id,
                                    'class_name' => $data->class->name . '-' . $data->class->medium->name,
                                    'class_streams' => $data->class->streams->name ?? null,
                                );
                            }
                        }
                    } else {
                        if ($exam_status == 2) {
                            if ($request->get_timetable == 1) {
                                $exam_data[] = array(
                                    'id' => $data->exam->id,
                                    'name' => $data->exam->name,
                                    'description' => $data->exam->description,
                                    'publish' => $data->exam->publish,
                                    'session_year' => $data->exam->session_year->name,
                                    'exam_starting_date' => $starting_date,
                                    'exam_ending_date' => $ending_date,
                                    'exam_status' => $exam_status,
                                    'exam_timetable' => $data->exam->timetable,
                                    'class_id' => $data->class_id,
                                    'class_name' => $data->class->name . '-' . $data->class->medium->name,
                                    'class_streams' => $data->class->streams->name ?? null,
                                );
                            } else {
                                $exam_data[] = array(
                                    'id' => $data->exam->id,
                                    'name' => $data->exam->name,
                                    'description' => $data->exam->description,
                                    'publish' => $data->exam->publish,
                                    'session_year' => $data->exam->session_year->name,
                                    'exam_starting_date' => $starting_date,
                                    'exam_ending_date' => $ending_date,
                                    'exam_status' => $exam_status,
                                    'class_id' => $data->class_id,
                                    'class_name' => $data->class->name . '-' . $data->class->medium->name,
                                    'class_streams' => $data->class->streams->name ?? null,
                                );
                            }
                        }
                    }
                } else {
                    if ($request->get_timetable == 1) {
                        $exam_data[] = array(
                            'id' => $data->exam->id,
                            'name' => $data->exam->name,
                            'description' => $data->exam->description,
                            'publish' => $data->exam->publish,
                            'session_year' => $data->exam->session_year->name,
                            'exam_starting_date' => $starting_date,
                            'exam_ending_date' => $ending_date,
                            'exam_status' => $exam_status,
                            'exam_timetable' => $data->exam->timetable,
                            'class_id' => $data->class_id,
                            'class_name' => $data->class->name . '-' . $data->class->medium->name,
                            'class_streams' => $data->class->streams->name ?? null,
                        );
                    } else {
                        $exam_data[] = array(
                            'id' => $data->exam->id,
                            'name' => $data->exam->name,
                            'description' => $data->exam->description,
                            'publish' => $data->exam->publish,
                            'session_year' => $data->exam->session_year->name,
                            'exam_starting_date' => $starting_date,
                            'exam_ending_date' => $ending_date,
                            'exam_status' => $exam_status,
                            'class_id' => $data->class_id,
                            'class_name' => $data->class->name . '-' . $data->class->medium->name,
                            'class_streams' => $data->class->streams->name ?? null,
                        );
                    }
                }
            }
            ResponseService::successResponse('Exam Marks Fetched Successfully', $exam_data ?? []);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getExamDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_id' => 'required|nullable',
            'class_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher = Auth::user()->teacher;
            $class_id = $request->class_id;
            $class_section = ClassSection::with('class', 'section', 'class.medium', 'class.streams')->where('class_id', $class_id)->first();

            $exam_data = Exam::with(['timetable' => function ($q) use ($request, $class_id) {
                $q->where(['exam_id' => $request->exam_id, 'class_id' => $class_id])->with('subject');
            }])->where('id', $request->exam_id)->get();
            ResponseService::successResponse('Data Fetched Successfully', $exam_data, [
                'class_id' => $class_id,
                'class_section_id' => $class_section->id,
                'class_name' => $class_section->class->name . '-' . $class_section->section->name . ' ' . $class_section->class->medium->name,
                'stream_name' => $class_section->class->streams->name ?? null,
                'code' => 200,
            ]);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getProfileDetails()
    {
        try {
            $user = Auth::user()->load(['teacher']);
            $dynamicFields = null;
            $dynamicField = $user->teacher->dynamic_fields;

            $user = flattenMyModel($user);

            $data = json_decode($dynamicField, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (!empty($item)) {
                        foreach ($item as $key => $value) {
                            $dynamicFields[$key] = $value;
                        }
                    }
                }
            } else {
                $dynamicFields = $data;
            }

            $user = array_merge($user, ['dynamic_fields' =>  $dynamicFields ?? null]);
            ResponseService::successResponse('Data Fetched Successfully', $user);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getNotifications()
    {
        try {
            // $user = $request->user()->id;
            $user = Auth::user()->id;
            $notification_id = UserNotification::where('user_id', $user)->pluck('notification_id');
            //Send To All Users(1) and Teachers(5)
            $notification = Notification::whereIn('id', $notification_id)->orWhereIn('send_to', [1, 5])->latest()->paginate();
            ResponseService::successResponse('Data Fetched Successfully', $notification ?? '');
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getChatUserList(Request $request)
    {
        try {

            $offset = $request->offset;
            $limit = $request->limit;
            $user_type = $request->isParent;
            $search = $request->search;

            // $session_year = getSettings('session_year');
            // $subject_teacher_section_ids = [];
            // $class_teacher_section_ids = [];

            $teacher = $request->user()->teacher;

            $class_section_ids = ClassTeacher::with('class_section')->where('class_teacher_id', $teacher->id)->pluck('class_section_id')->toArray();

            $subject_teachers = SubjectTeacher::with('class_section')->where('teacher_id', $teacher->id)->whereNotIn('class_section_id', $class_section_ids)->groupBy('class_section_id')->get();
            $data = [];
            $parents_ids = [];


            if ($class_section_ids) {
                $students = Students::with(['user', 'class_section.class', 'student_subjects.subject'])->whereIn('class_section_id', $class_section_ids)->get();

                foreach ($students as $student) {
                    $parents_ids[] = $student->father_id;
                    $parents_ids[] = $student->mother_id;
                    $parents_ids[] = $student->guardian_id;
                }

                $parents_ids = array_filter(array_unique($parents_ids));

                if ($user_type == 0) {
                    foreach ($students as $student) {
                        $unreadCount = 0;
                        if ($student->user_id != 0) {
                            $lastMessage = ChatMessage::with('file')->where(function ($query) use ($student, $teacher) {
                                $query->where('modal_id', $student->user_id)
                                    ->where('sender_id', $teacher->user->id);
                            })
                                ->orWhere(function ($query) use ($student, $teacher) {
                                    $query->where('modal_id', $teacher->user->id)
                                        ->where('sender_id', $student->user_id);
                                })
                                ->select('id', 'body', 'date')
                                ->latest()
                                ->first();

                            $lastReadMessage = ReadMessage::where('modal_id', $teacher->user->id)->where('user_id', $student->user_id)->first();

                            if ($lastReadMessage) {

                                $lastReadMessageId = $lastReadMessage->last_read_message_id;
                                if (!empty($lastReadMessageId)) {
                                    $unreadCount = ChatMessage::where('sender_id', $student->user_id)->where('modal_id', $teacher->user->id)->where('id', '>', $lastReadMessageId)->count();
                                } else {
                                    $unreadCount = ChatMessage::where('sender_id', $student->user_id)->where('modal_id', $teacher->user->id)->count();
                                }
                            }

                            $student_subject = $student->subjects();

                            $core_subjects = array_column($student_subject["core_subject"], 'subject_id');

                            $elective_subjects = $student_subject["elective_subject"] ?? [];
                            if ($elective_subjects) {
                                $elective_subjects = $elective_subjects->pluck('subject_id')->toArray();
                            }
                            $subject_id = array_merge($core_subjects, $elective_subjects);


                            $subjects = Subject::whereIn('id', $subject_id)->select('id', 'name')->get();

                            $data[] = [
                                'id' => $student->id,
                                'user_id' => $student->user_id, // Assuming this is the correct property name
                                'first_name' => $student->user->first_name ?? '',
                                'last_name' => $student->user->last_name ?? '',
                                'image' => $student->user->image ?? '',
                                'roll_no' => $student->roll_number,
                                'admission_no' => $student->admission_no,
                                'gender' => $student->user->gender,
                                'dob' => $student->user->dob,
                                'subjects' => $subjects,
                                'address' => $student->user->current_address,
                                'last_message' => $lastMessage ?? null,
                                'class_name' => $student->class_section->class->name . ' ' . $student->class_section->section->name . ' ' . $student->class_section->class->medium->name,
                                'isParent' => $user_type,
                                'unread_message' => $unreadCount ?? 0
                            ];
                        }
                    }
                }
                if ($user_type == 1) {

                    $parents = Parents::with('user')->whereIn('id', $parents_ids)->get();
                    foreach ($parents as $parent) {
                        $unreadCount = 0;
                        $childArray = [];
                        if ($parent->user_id != 0) {
                            $children = $parent->children()->with('user', 'class_section')->get();

                            foreach ($children as $child) {
                                $child_subject = $child->subjects();

                                $core_subjects = array_column($child_subject["core_subject"], 'subject_id');

                                $elective_subjects = $child_subject["elective_subject"] ?? [];

                                if ($elective_subjects) {
                                    $elective_subjects = $elective_subjects->pluck('subject_id')->toArray();
                                }

                                $subject_id = array_merge($core_subjects, $elective_subjects);

                                $subjects = Subject::whereIn('id', $subject_id)->select('id', 'name')->get();

                                $childArray[] = [
                                    'id' => $child->id,
                                    'user_id' => $child->user_id,
                                    'child_name' => $child->user->first_name . ' ' . $child->user->last_name,
                                    'class_name' => $child->class_section->class->name . ' ' . $child->class_section->section->name . ' ' . $child->class_section->class->medium->name,
                                    'admission_no' => $child->admission_no,
                                    'image' => $child->user->image,
                                    'subject' => $subjects ?? []
                                ];
                            }

                            $lastMessage = ChatMessage::with('file')->where(function ($query) use ($parent, $teacher) {
                                $query->where('modal_id', $parent->user_id)
                                    ->where('sender_id', $teacher->user->id);
                            })
                                ->orWhere(function ($query) use ($parent, $teacher) {
                                    $query->where('modal_id', $teacher->user->id)
                                        ->where('sender_id', $parent->user_id);
                                })
                                ->select('body', 'date')
                                ->latest()
                                ->first();

                            $lastReadMessage = ReadMessage::where('modal_id', $teacher->user->id)->where('user_id', $parent->user_id)->first();

                            if ($lastReadMessage) {

                                $lastReadMessageId = $lastReadMessage->last_read_message_id;
                                if (!empty($lastReadMessageId)) {
                                    $unreadCount = ChatMessage::where('sender_id', $parent->user_id)->where('modal_id', $teacher->user->id)->where('id', '>', $lastReadMessageId)->count();
                                } else {
                                    $unreadCount = ChatMessage::where('sender_id', $parent->user_id)->where('modal_id', $teacher->user->id)->count();
                                }
                            }
                            $data[] = [
                                'id' => $parent->id,
                                'user_id' => $parent->user_id, // Assuming this is the correct property name
                                'first_name' => $parent->user->first_name ?? '',
                                'last_name' => $parent->user->last_name ?? '',
                                'email' => $parent->user->email ?? '',
                                'mobile_no' => $parent->user->mobile ?? '',
                                'occupation' => $parent->occupation ?? '',
                                'image' => $parent->user->image ?? '',
                                'last_message' => $lastMessage ?? null,
                                'children' => $childArray ?? [],
                                'isParent' => $user_type,
                                'unread_message' => $unreadCount ?? 0
                            ];
                        }
                    }
                }
            }

            if ($subject_teachers) {

                foreach ($subject_teachers as $subject_teacher) {
                    $class_subject = ClassSubject::where('subject_id', $subject_teacher->subject_id)->where('class_id', $subject_teacher->class_section->class->id)->first();
                    $students = Students::with(['user', 'class_section.class', 'student_subjects.subject'])->where('class_section_id', $subject_teacher->class_section_id)->get();
                    $parents_id = [];

                    $parents_id = $students->groupBy(['father_id', 'mother_id', 'guardian_id'])->keys()->all();

                    $common_parents_ids = array_intersect($parents_ids, $parents_id);

                    $unique_parents_ids = array_diff($parents_id, $common_parents_ids);

                    if ($user_type == 0) {
                        foreach ($students as $student) {
                            $unreadCount = 0;
                            if ($student->user_id != 0) {
                                $lastMessage = ChatMessage::with('file')->where(function ($query) use ($student, $teacher) {
                                    $query->where('modal_id', $student->user_id)
                                        ->where('sender_id', $teacher->user->id);
                                })
                                    ->orWhere(function ($query) use ($student, $teacher) {
                                        $query->where('modal_id', $teacher->user->id)
                                            ->where('sender_id', $student->user_id);
                                    })
                                    ->select('id', 'body', 'date')
                                    ->latest()
                                    ->first();

                                $lastReadMessage = ReadMessage::where('modal_id', $teacher->user->id)->where('user_id', $student->user_id)->first();

                                if ($lastReadMessage) {

                                    $lastReadMessageId = $lastReadMessage->last_read_message_id;
                                    if (!empty($lastReadMessageId)) {
                                        $unreadCount = ChatMessage::where('sender_id', $student->user_id)->where('modal_id', $teacher->user->id)->where('id', '>', $lastReadMessageId)->count();
                                    } else {
                                        $unreadCount = ChatMessage::where('sender_id', $student->user_id)->where('modal_id', $teacher->user->id)->count();
                                    }
                                }

                                $student_subject = $student->subjects();

                                $core_subjects = array_column($student_subject["core_subject"], 'subject_id');

                                $elective_subjects = $student_subject["elective_subject"] ?? [];
                                if ($elective_subjects) {
                                    $elective_subjects = $elective_subjects->pluck('subject_id')->toArray();
                                }
                                $subject_id = array_merge($core_subjects, $elective_subjects);


                                $subjects = Subject::whereIn('id', $subject_id)->select('id', 'name')->get();
                                $subjectArray = [];
                                foreach ($subjects as $subject) {
                                    $subjectArray[] = array(
                                        'id' => $subject->id,
                                        'name' => $subject->name
                                    );
                                }
                                if ($class_subject->type == "Elective") {
                                    // dd($student_subject['elective_subject']->pluck('subject_id'));

                                    $student_subject = $student->student_subjects->where('subject_id', $class_subject->subject_id);

                                    if (!empty($student_subject->toArray())) {
                                        $data[] = [
                                            'id' => $student->id,
                                            'user_id' => $student->user_id, // Assuming this is the correct property name
                                            'first_name' => $student->user->first_name ?? '',
                                            'last_name' => $student->user->last_name ?? '',
                                            'image' => $student->user->image ?? '',
                                            'roll_no' => $student->roll_number,
                                            'admission_no' => $student->admission_no,
                                            'gender' => $student->user->gender,
                                            'dob' => $student->user->dob,
                                            'subjects' =>  $subjectArray,
                                            'address' => $student->user->current_address,
                                            'last_message' => $lastMessage ?? null,
                                            'class_name' => $student->class_section->class->name . ' ' . $student->class_section->section->name . ' ' . $student->class_section->class->medium->name,
                                            'isParent' => $user_type,
                                            'unread_message' => $unreadCount ?? 0
                                        ];
                                    }
                                } else {

                                    $data[] = [
                                        'id' => $student->id,
                                        'user_id' => $student->user_id, // Assuming this is the correct property name
                                        'first_name' => $student->user->first_name ?? '',
                                        'last_name' => $student->user->last_name ?? '',
                                        'image' => $student->user->image ?? '',
                                        'roll_no' => $student->roll_number,
                                        'admission_no' => $student->admission_no,
                                        'gender' => $student->user->gender,
                                        'dob' => $student->user->dob,
                                        'subjects' => $subjects,
                                        'address' => $student->user->current_address,
                                        'last_message' => $lastMessage ?? null,
                                        'class_name' => $student->class_section->class->name . ' ' . $student->class_section->section->name . ' ' . $student->class_section->class->medium->name,
                                        'isParent' => $user_type,
                                        'unread_message' => $unreadCount ?? 0
                                    ];
                                }
                            }
                        }
                    }

                    if ($user_type == 1) {
                        $parents = Parents::with('user')->whereIn('id', $unique_parents_ids)->get();
                        foreach ($parents as $parent) {
                            $unreadCount = 0;
                            $childArray = [];
                            if ($parent->user_id != 0) {
                                $lastMessage = ChatMessage::with('file')->where(function ($query) use ($parent, $teacher) {
                                    $query->where('modal_id', $parent->user_id)
                                        ->where('sender_id', $teacher->user->id);
                                })
                                    ->orWhere(function ($query) use ($parent, $teacher) {
                                        $query->where('modal_id', $teacher->user->id)
                                            ->where('sender_id', $parent->user_id);
                                    })
                                    ->select('body', 'date')
                                    ->latest()
                                    ->first();

                                $lastReadMessage = ReadMessage::where('modal_id', $teacher->user->id)->where('user_id', $parent->user_id)->first();

                                if ($lastReadMessage) {

                                    $lastReadMessageId = $lastReadMessage->last_read_message_id;
                                    if (!empty($lastReadMessageId)) {
                                        $unreadCount = ChatMessage::where('sender_id', $parent->user_id)->where('modal_id', $teacher->user->id)->where('id', '>', $lastReadMessageId)->count();
                                    } else {
                                        $unreadCount = ChatMessage::where('sender_id', $parent->user_id)->where('modal_id', $teacher->user->id)->count();
                                    }
                                }
                                $children = $parent->children()->with('user', 'class_section')->get();

                                foreach ($children as $child) {
                                    $child_subject = $child->subjects();

                                    $core_subjects = array_column($child_subject["core_subject"], 'subject_id');

                                    $elective_subjects = $child_subject["elective_subject"] ?? [];

                                    if ($elective_subjects) {
                                        $elective_subjects = $elective_subjects->pluck('subject_id')->toArray();
                                    }

                                    $subject_id = array_merge($core_subjects, $elective_subjects);

                                    $subjects = Subject::whereIn('id', $subject_id)->select('id', 'name')->get();

                                    $childArray[] = [
                                        'id' => $child->id,
                                        'user_id' => $child->user_id,
                                        'child_name' => $child->user->first_name . ' ' . $child->user->last_name,
                                        'class_name' => $child->class_section->class->name . ' ' . $child->class_section->section->name . ' ' . $child->class_section->class->medium->name,
                                        'admission_no' => $child->admission_no,
                                        'image' => $child->user->image,
                                        'subject' => $subjects ?? []
                                    ];
                                }

                                if ($class_subject->type == "Elective") {
                                    $student_subject = $child->student_subjects->where('subject_id', $class_subject->subject_id);

                                    if (!empty($student_subject->toArray())) {
                                        $data[] = [
                                            'id' => $parent->id,
                                            'user_id' => $parent->user_id, // Assuming this is the correct property name
                                            'first_name' => $parent->user->first_name ?? '',
                                            'last_name' => $parent->user->last_name ?? '',
                                            'email' => $parent->user->email ?? '',
                                            'mobile_no' => $parent->user->mobile ?? '',
                                            'occupation' => $parent->occupation ?? '',
                                            'image' => $parent->user->image ?? '',
                                            'last_message' => $lastMessage ?? null,
                                            'children' => $childArray ?? [],
                                            'isParent' => $user_type,
                                            'unread_message' => $unreadCount ?? 0
                                        ];
                                    }
                                } else {
                                    $data[] = [
                                        'id' => $parent->id,
                                        'user_id' => $parent->user_id, // Assuming this is the correct property name
                                        'first_name' => $parent->user->first_name ?? '',
                                        'last_name' => $parent->user->last_name ?? '',
                                        'email' => $parent->user->email ?? '',
                                        'mobile_no' => $parent->user->mobile ?? '',
                                        'occupation' => $parent->occupation ?? '',
                                        'image' => $parent->user->image ?? '',
                                        'last_message' => $lastMessage ?? null,
                                        'children' => $childArray ?? [],
                                        'isParent' => $user_type,
                                        'unread_message' => $unreadCount ?? 0
                                    ];
                                }
                            }
                        }
                    }
                }
            }
            $total_items = count($data) ?? 0;

            $unreadusers = array_filter($data, function ($user) {
                return $user['unread_message'] > 0;
            });

            $totalunreadusers = count($unreadusers);


            if ($search) {
                $filteredData = array_filter($data, function ($teacher) use ($search) {
                    $name = $teacher['first_name'] . ' ' . $teacher['last_name'];
                    return stristr($name, $search) !== false;
                });
                $data = collect($filteredData)->sortByDesc(function ($user) {
                    return optional($user['last_message'])->date ?? 0;
                })->splice($offset, $limit)->values();
            } else {
                $data = collect($data)->sortByDesc(function ($user) {
                    return optional($user['last_message'])->date ?? 0;
                })
                    ->splice($offset, $limit)
                    ->values();
            }
            ResponseService::successResponse('Data Fetched Successfully', ['items' => $data, 'total_items' => $total_items, 'total_unread_users' => $totalunreadusers], [], 100);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function sendMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver_id' => 'required|numeric',
            'message' => 'required_without:file',
            'file.*' => 'nullable'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sender_id = $request->user()->id;
            $receiver_id = $request->receiver_id;

            $message = new ChatMessage();
            $message->modal_id = $receiver_id;
            $message->modal_type = 'App/Models/User';
            $message->sender_id = $sender_id;
            $message->body = $request->message ?? '';
            $message->date = Carbon::now();
            $message->save();

            $count = 0;
            $unreadCount = 0;

            if ($request->hasFile('file')) {
                foreach ($request->file('file') as $uploadedFile) {

                    $originalName = $uploadedFile->getClientOriginalName();
                    $filePath = $uploadedFile->storeAs('chatfile', $originalName, 'public');

                    $file = new ChatFile();
                    $file->file_type = 1;
                    $file->file_name =  $filePath;
                    $file->message_id = $message->id;
                    $file->save();
                    $count++;
                }
            }

            $readMessage = ReadMessage::where('modal_id', $receiver_id)->where('user_id', $sender_id)->first();
            if (empty($readMessage)) {
                $readMessage = new ReadMessage();
                $readMessage->modal_id = $receiver_id;
                $readMessage->modal_type = 'App/Models/User';
                $readMessage->user_id = $sender_id;
                $readMessage->save();
            }

            $message = ChatMessage::with('file')->where('id', $message->id)->select('id', 'sender_id', 'body', 'date')->get();

            foreach ($message as $message) {
                $chatfile = [];
                foreach ($message->file as $file) {
                    if (!empty($file)) {
                        $chatfile[] =  asset('storage/' . $file->file_name);
                    } else {
                        $chatfile[] = '';
                    }
                }

                $data = array(
                    'id' => $message->id,
                    'sender_id' => $message->sender_id,
                    'body' => $message->body,
                    'date' => $message->date,
                    'files' => $chatfile
                );
            }

            $teacher = Teacher::with('user', 'subjects.subject')->where('user_id', $sender_id)->first();

            $subjectData = [];

            if ($teacher) {
                foreach ($teacher->subjects as $subject) {
                    $subjectData[] = [
                        'id' => $subject->subject->id,
                        'name' => $subject->subject->name,
                    ];
                }
            }


            $lastReadMessage = ReadMessage::where('modal_id', $receiver_id)->where('user_id', $teacher->user_id)->first();

            if ($lastReadMessage) {

                $lastReadMessageId = $lastReadMessage->last_read_message_id;
                if (!empty($lastReadMessageId)) {
                    $unreadCount = ChatMessage::where('modal_id', $receiver_id)->where('sender_id', $teacher->user_id)->where('id', '>', $lastReadMessageId)->count();
                } else {
                    $unreadCount = ChatMessage::where('modal_id', $receiver_id)->where('sender_id', $teacher->user_id)->count();
                }
            }

            $userinfo = [
                'id' => $teacher->id,
                'user_id' => $teacher->user->id,
                'first_name' => $teacher->user->first_name,
                'last_name' => $teacher->user->last_name,
                'email' => $teacher->user->email,
                'qualification' => $teacher->qualification,
                'image' =>  $teacher->user->image,
                'mobile_no' => $teacher->user->mobile,
                'subjects' => $subjectData,
                'last_message' => $data ?? null,
                'unread_message' => $unreadCount ?? 0
            ];

            $title = $teacher->user->first_name . ' ' . $teacher->user->last_name;
            $body = $request->message ??  $count . " Files Received";
            $type = "chat";
            $image = null;
            $user[] = $receiver_id;

            $userinfo = (object)$userinfo;
            sendSimpleNotification($user, $title, $body, $type, $image, $userinfo);
            ResponseService::successResponse('message_sent_successfully', $data);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getUserChatMessage(Request $request)
    {
        try {

            $offset = $request->offset;
            $limit = $request->limit;

            $messages = ChatMessage::with(['file' => function ($query) {
                $query->select('message_id', 'file_name');
            }])
                ->where(function ($query) use ($request) {
                    $query->where('modal_id', $request->user_id)
                        ->orWhere('modal_id', Auth::id());
                })
                ->where(function ($query) use ($request) {
                    $query->where('sender_id', $request->user_id)
                        ->orWhere('sender_id', Auth::id());
                })
                ->select('id', 'sender_id', 'body', 'date')
                ->latest('date');


            $total_items = $messages->count();

            $messages = $messages->offset($offset)->limit($limit)->get()->toArray();


            foreach ($messages as &$message) {
                $message['files'] = collect($message['file'])->map(function ($file) {
                    return  asset('storage/' . $file['file_name']);
                })->toArray();

                unset($message['file']);
            }
            ResponseService::successResponse('Data Fetched Successfully', ['items' => $messages ?? [], 'total_items' => $total_items], [], 100);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function readAllMessages(Request $request)
    {
        try {
            $auth = Auth::id();
            $user = $request->user_id;


            $lastMessage = ChatMessage::where('sender_id', $user)->where('modal_id', $auth)->latest()->first();
            if ($lastMessage) {
                $message_id = $lastMessage->id;
            }

            // Update Read Message id
            $readMessage = ReadMessage::where('modal_id', $auth)->where('user_id', $user)->first();

            if ($readMessage) {
                $readMessage->last_read_message_id = $message_id;
                $readMessage->save();
            }
            ResponseService::successResponse('Message Read');
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getStudentResultPdf(Request $request)
    {
        try {
            $father_name = null;
            $mother_name = null;
            $guardian_name = null;

            $id = $request->student_id;
            $date = date('d-m-Y', strtotime(Carbon::now()->toDateString()));

            $settings = getSettings();
            $sessionYear = SessionYear::select('name')->where('id', $settings['session_year'])->pluck('name')->first();

            $student = Students::select('id', 'roll_number', 'admission_no', 'admission_date', 'user_id', 'class_section_id', 'guardian_id', 'father_id', 'mother_id')->with('user:id,first_name,last_name,dob', 'class_section.class:id,name,medium_id,stream_id', 'class_section.class.medium:id,name', 'class_section.class.streams:id,name', 'father:id,first_name,last_name', 'guardian:id,first_name,last_name')->where('id', $id)->first();

            $student_name = $student->user->first_name . ' ' . $student->user->last_name;

            if ($student->father) {
                $father_name = $student->father->first_name . ' ' . $student->father->last_name;
                $mother_name = $student->mother->first_name . ' ' . $student->mother->last_name;
            }

            if ($student->guardian) {
                $guardian_name = $student->guardian->first_name . ' ' . $student->guardian->last_name;
            }
            $admission_date = $student->admission_date;
            $gr_no = $student->admission_no;
            $dob = date('d-m-Y', strtotime($student->user->dob));
            $roll_number = $student->roll_number;
            $class_section = $student->class_section->class->name . ' ' . $student->class_section->section->name . ' ' . $student->class_section->class->medium->name . ' ' . ($student->class_section->class->streams->name ?? '');

            $class_id = $student->class_section->class->id;

            $student_subject = $student->subjects();
            $core_subjects = array_column($student_subject["core_subject"], 'subject_id');
            $elective_subjects = $student_subject["elective_subject"] ?? [];
            if ($elective_subjects) {
                $elective_subjects = $elective_subjects->pluck('subject_id')->toArray();
            }
            $subject_id = array_merge($core_subjects, $elective_subjects);

            $subjects = Subject::whereIn('id', $subject_id)->get();


            $exams = Exam::with(['exam_classes' => function ($q) use ($class_id) {
                $q->where('class_id', $class_id);
            }])
                ->with(['timetable' => function ($q) use ($class_id, $subject_id) {
                    $q->where('class_id', $class_id)->whereIn('subject_id', $subject_id);
                }])
                ->where('session_year_id', $settings['session_year'])
                ->where('publish', 1)
                ->whereHas('timetable', function ($q) use ($class_id, $subject_id) {
                    $q->where('class_id', $class_id)->whereIn('subject_id', $subject_id);
                })->get();

            $examarray = [];

            foreach ($exams as $exam) {
                $timetable = $exam->timetable;

                $filtered_timetable = [];

                foreach ($timetable as $exam_timetable) {
                    if (in_array($exam_timetable->subject_id, $subject_id)) {
                        $exam_marks = ExamMarks::where('exam_timetable_id', $exam_timetable->id)
                            ->where('student_id', $student->id)
                            ->where('session_year_id', $settings['session_year'])
                            ->first();

                        $filtered_timetable[] = array(
                            'id' => $exam_timetable->id,
                            'exam_id' => $exam_timetable->exam_id,
                            'class_id' => $exam_timetable->class_id,
                            'subject_id' => $exam_timetable->subject_id,
                            'total_marks' => $exam_timetable->total_marks,
                            'passing_marks' => $exam_timetable->passing_marks,
                            'session_year' => $exam_timetable->session_year_id,
                            'exam_marks' => $exam_marks
                        );
                    }
                }

                if (!empty($filtered_timetable)) {
                    $examarray[] = array(
                        'id' => $exam->id,
                        'name' => $exam->name,
                        'publish' => $exam->publish,
                        'timetable' => $filtered_timetable
                    );
                }
            }


            $subjectMarks = [];
            $totalMarks = null;

            foreach ($subjects as $subject) {
                $examObtainedMarks = null;
                $examTotalMarks = null;
                $subjectGrade = null;
                $subjectType = $subject->type;

                foreach ($examarray as $exam_data) {
                    foreach ($exam_data['timetable'] as $timetable) {
                        if ($timetable['subject_id'] == $subject->id) {
                            $exam_marks = $timetable['exam_marks'];

                            if ($exam_marks) {
                                $ObtainedMarks = $exam_marks['obtained_marks'];
                                $totalMarks = $timetable['total_marks'];

                                $examObtainedMarks += $ObtainedMarks;
                                $examTotalMarks += $totalMarks;

                                $subjectMarks[$subject->name . ' (' . $subjectType . ')'][$exam_data['name']] = $ObtainedMarks . '/' . $totalMarks;

                                if ($totalMarks > 0) {  // Check if totalMarks is greater than 0
                                    $percent = round(($ObtainedMarks / $totalMarks) * 100, 2);
                                    $subjectGrade = DB::table('grades')
                                        ->where('starting_range', '<=', $percent)
                                        ->where('ending_range', '>=', $percent)
                                        ->pluck('grade')
                                        ->first();
                                } else {
                                    $subjectGrade = null; // If totalMarks is 0 or not set, set grade to null
                                }
                            }

                            break 2;
                        }
                    }
                }

                // Store subject-wise total marks
                $subjectMarks[$subject->name . ' (' . $subjectType . ')']['total_obtained'] = $examObtainedMarks;
                $subjectMarks[$subject->name . ' (' . $subjectType . ')']['total_marks'] = $examTotalMarks;
                $subjectMarks[$subject->name . ' (' . $subjectType . ')']['grade'] = $subjectGrade;
            }

            $obtainmarks = array_sum(array_column($subjectMarks, 'total_obtained'));
            $totalmarks = array_sum(array_column($subjectMarks, 'total_marks')) ?? '';

            if ($obtainmarks == null && $totalmarks == null) {
                $percentage = null;
                $grade = null;
                $result = null;
            } else {
                $percentage = round(($obtainmarks / $totalmarks) * 100, 2);
                $grade = DB::table('grades')
                    ->where('starting_range', '<=', $percentage)
                    ->where('ending_range', '>=', $percentage)
                    ->pluck('grade')
                    ->first();
                $result = ($percentage >= 40) ? "Passed" : "Failed";
            }

            $data = [
                'student_name' => $student_name,
                'guardian_name' => $father_name ?? $guardian_name,
                'gr_no' => $gr_no,
                'dob' => $dob,
                'roll_number' => $roll_number,
                'class_section' => $class_section,
                'sessionYear' => $sessionYear,
                'date' => $date,
                'father_name' => $father_name,
                'subjects' => $subjectMarks,
                'totalMarks' => $totalmarks,
                'obtainmarks' => $obtainmarks,
                'percentage' => $percentage,
                'grade' => $grade,
                'result' => $result
            ];
            //Load the HTML
            $pdf = PDF::loadView('students.result_template', compact('data', 'settings', 'exams', 'subjects'));

            //Get The Output Of PDF
            $output = $pdf->output();
            ResponseService::successResponse('Data Fetched Successfully', null, ['pdf' => base64_encode($output)]);
        } catch (Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function applyLeave(Request $request)
    {
        $allowed = ['reason', 'leave_details', 'files'];

        // reject any extra keys
        $unknown = array_diff(array_keys($request->all()), $allowed);

        if (!empty($unknown)) {
            ResponseService::validationError("Unknown fields: " . implode(', ', $unknown));
        }

        $validator = Validator::make($request->all(), [
            'reason'          => 'required',
            'leave_details.*' => 'required|array',

            // This enforces that "files" must be an array
            'files'           => 'nullable|array',

            // Only if files is a proper array, validate each file
            'files.*' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp,image/gif,image/bmp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $session_year = getSettings('session_year');
            $session_year_id = $session_year['session_year'];

            // $sessionYear = SessionYear::where('id', $session_year_id)->first();

            $leave_master = LeaveMaster::where('session_year_id', $session_year_id)->first();

            // $public_holiday = Holiday::whereDate('date', '>=', $sessionYear->start_date)->whereDate('date', '<=', $sessionYear->end_date)->get()->pluck('date')->toArray();


            if (!$leave_master) {
                ResponseService::successResponse('Kindly contact the school admin to update settings for continued access.');
            }

            $dates = array_column($request->leave_details, 'date');
            $from_date = min($dates);
            $to_date = max($dates);

            $data = [
                'user_id' => $request->user()->id,
                'reason' => $request->reason,
                'from_date' => $from_date,
                'to_date' => $to_date,
                'leave_master_id' => $leave_master->id,
                'session_year_id' => $session_year_id,
                'status' => "0"
            ];

            $leave = Leave::create($data);

            // $leave_details = array();

            foreach ($request->leave_details as $key => $value) {
                $leaveDetail = new LeaveDetail();
                $leaveDetail->leave_id = $leave->id;
                $leaveDetail->date = date('Y-m-d', strtotime($value['date']));
                $leaveDetail->type = $value['type'];
                $leaveDetail->save();
            }

            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file_upload) {
                    $file = new File();
                    $file->modal_type = "App\Models\Leave";
                    $file->modal_id = $leave->id;
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('leave', 'public');
                    $file->save();
                }
            }
            ResponseService::successResponse('data_store_successfully', $leave ?? '');
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getMyLeave(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month'  => 'in:1,2,3,4,5,6,7,8,9,10,11,12',
            'status' => 'in:0,1,2'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $setting = getSettings();
            $session_year_id = $request->session_year_id ?? $setting['session_year'];
            $leaveMaster = LeaveMaster::where('session_year_id', $session_year_id)->first();
            $sql = Leave::with('leave_detail', 'file')->where('user_id', Auth::user()->id)->withCount(['leave_detail as full_leave' => function ($q) {
                $q->where('type', 'Full');
            }])->withCount(['leave_detail as half_leave' => function ($q) {
                $q->whereNot('type', 'Full');
            }])->whereHas('leave_master', function ($q) use ($session_year_id) {
                $q->where('session_year_id',  $session_year_id);
            })->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            })->when($request->month, function ($q) use ($request) {
                $q->whereHas('leave_detail', function ($q) use ($request) {
                    $q->whereMonth('date', $request->month);
                });
            })->orderBy('id', 'DESC')->get();


            $sql = $sql->map(function ($sql) {
                $total_leaves = ($sql->half_leave / 2) + $sql->full_leave;
                $sql->days = $total_leaves;
                return $sql;
            });

            $data = [
                'monthly_allowed_leaves' => $leaveMaster->total_leave ?? 0,
                'taken_leaves' => $sql->where('status', 1)->sum('days'),
                'leave_details' => $sql
            ];
            ResponseService::successResponse('Data Fetched Successfully', $data);
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function deleteLeave(Request $request)
    {
        try {

            $leave = Leave::findOrFail($request->leave_id);
            $leave->delete();
            ResponseService::successResponse('Data Deleted Successfully', null, [], 100);
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function getStudentLeaveList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month'  => 'in:1,2,3,4,5,6,7,8,9,10,11,12',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            // $setting = getSettings();
            // $session_year_id = $setting['session_year'];

            $teacher_id = Auth::user()->teacher->id;
            $class_section_ids = ClassTeacher::where('class_teacher_id', $teacher_id)->pluck('class_section_id');

            $sql = Leave::with('leave_detail', 'user.student', 'file')
                ->whereHas('user', function ($query) use ($class_section_ids) {
                    $query->whereHas('roles', function ($q) {
                        $q->where('name', 'Student');
                    })->whereHas('student', function ($q) use ($class_section_ids) {
                        $q->whereIn('class_section_id', $class_section_ids);
                    });
                });

            if ($request->session_year_id) {
                $sql->where('session_year_id', $request->session_year_id);
            }

            if ($request->month) {
                $sql->whereHas('leave_detail', function ($q) use ($request) {
                    $q->whereMonth('date', $request->month);
                });
            }

            if ($request->class_section_id) {
                $sql->whereHas('user', function ($query) use ($request) {
                    $query->whereHas('student', function ($q) use ($request) {
                        $q->where('class_section_id', $request->class_section_id);
                    });
                });
            }

            $sql->orderBy('id', 'DESC');
            $sql = $sql->get();

            foreach ($sql as $leave) {
                $totalDays = 0;

                foreach ($leave->leave_detail as $detail) {
                    if ($detail->type == 'Full') {
                        $totalDays += 1; // 1 day for a Full leave
                    } else {
                        $totalDays += 0.5; // 0.5 day for a Half leave
                    }
                }

                $leave->total_days = $totalDays;
            }

            $data = [
                'total_leave_requests' => $sql->count(),
                'leave_details' => $sql
            ];
            ResponseService::successResponse('Data Fetched Successfully', $data);
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function studentLeaveStatusUpdate(Request $request)
    {
        $request->validate([
            'leave_id' => 'required|integer',
            'status' => 'required|in:0,1,2',
            'reason_of_rejection' => 'nullable|string',
        ]);

        try {
            $leave = Leave::findOrFail($request->leave_id);

            $student = Students::with('user')->where('user_id', $leave->user_id)->first();

            $fatherId = $student->father_id;
            $motherId = $student->mother_id;
            $guardianId = $student->guardian_id;

            $parentUserIds = Parents::whereIn('id', [
                $fatherId,
                $motherId,
                $guardianId
            ])->pluck('user_id');

            $leave->status = $request->status;
            $leave->reason_of_rejection = $request->reason_of_rejection;
            $leave->save();

            $title = 'Leave Alert';
            $type = 'leave';
            $image = null;
            $userinfo = null;

            $fullName = $student->user->first_name . ' ' . $student->user->last_name;

            if ($request->status == 1) {
                $body = "{$fullName} leave has been approved.";
            } else if ($request->status == 2) {
                if ($request->reason_of_rejection) {
                    $body = "{$fullName} leave is rejected due to {$request->reason_of_rejection}.";
                } else {
                    $body = "{$fullName} leave is rejected.";
                }
            } else {
                return ResponseService::successResponse('data_update_successfully');
            }

            // No mass assignment here
            $notification = new Notification();
            $notification->send_to = 3;
            $notification->title = $title;
            $notification->message = $body;
            $notification->type = $type;
            $notification->date = Carbon::now();
            $notification->is_custom = 0;
            $notification->save();

            $rows = $parentUserIds->map(fn($id) => [
                'notification_id' => $notification->id,
                'user_id' => $id,
            ])->toArray();

            UserNotification::insert($rows);

            sendSimpleNotification($parentUserIds, $title, $body, $type, $image, $userinfo);

            return ResponseService::successResponse('data_update_successfully');
        } catch (\Throwable $e) {
            return ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }



    public function dashboard(Request $request)
    {
        try {

            $current_day = Carbon::now()->format('l');
            $current_date = Carbon::now()->format('Y-m-d');
            $setting = getSettings();
            $session_year_id = $setting['session_year'];

            $teacher = $request->user()->teacher;
            $user_id = $teacher->user_id;
            $class_section_id = $teacher->class_sections->pluck('class_section_id');

            //Find the class in which teacher is assigns as Class Teacher
            if ($teacher->class_sections) {

                $class_teacher = ClassSection::whereIn('id', $class_section_id)->with('class.medium', 'section', 'class.streams', 'class.shifts')->get();
            }

            //Find the Classes in which teacher is taking subjects
            $class_section_ids = $teacher->classes()->pluck('class_section_id');

            $class_sections = ClassSection::whereIn('id', $class_section_ids)->with('class.medium', 'section', 'class.streams', 'class.shifts')->get();
            $class_section = $class_sections->diff($class_teacher);

            $class_teacher_section_ids = ClassTeacher::where('class_teacher_id', $teacher->id)->pluck('class_section_id');

            $student_leave_request = Leave::where('status', 0)->where('to_date', '>=', $current_date)->with('leave_detail', 'user.student.class_section', 'file')
                ->whereHas('user', function ($query) use ($class_teacher_section_ids) {
                    $query->whereHas('roles', function ($q) {
                        $q->where('name', 'Student');
                    })->whereHas('student', function ($q) use ($class_teacher_section_ids) {
                        $q->whereIn('class_section_id', $class_teacher_section_ids)->with('class_section');
                    });
                });


            if ($request->session_year_id) {
                $student_leave_request->where('session_year_id', $request->session_year_id);
            }

            if ($request->month) {
                $student_leave_request->whereHas('leave_detail', function ($q) use ($request) {
                    $q->whereMonth('date', $request->month);
                });
            }



            if ($request->class_section_id) {
                $student_leave_request->whereHas('user', function ($query) use ($request) {
                    $query->whereHas('student', function ($q) use ($request) {
                        $q->where('class_section_id', $request->class_section_id);
                    });
                });
            }


            $student_leave_request->orderBy('id', 'DESC');
            $student_leave_request = $student_leave_request->get();

            foreach ($student_leave_request as $leave) {
                $totalDays = 0;

                foreach ($leave->leave_detail as $detail) {
                    if ($detail->type == 'Full') {
                        $totalDays += 1; // 1 day for a Full leave
                    } else {
                        $totalDays += 0.5; // 0.5 day for a Half leave
                    }
                }

                $leave->total_days = $totalDays;
            }


            $subject_id = SubjectTeacher::where('teacher_id', $teacher->id)->pluck('id');
            $timetable = Timetable::whereIn('subject_teacher_id', $subject_id)->where('day_name', $current_day)->with('class_section', 'subject')->orderBy('start_time', 'ASC')->get();

            $class_ids = ClassSection::with('class')->whereIn('id', $class_teacher_section_ids)->pluck('class_id');

            $sql = ExamClass::with('exam.session_year:id,name', 'exam.timetable.subject', 'class', 'class.medium', 'class.streams')->whereIn('class_id', $class_ids);

            $exam_data_db = $sql->get();

            foreach ($exam_data_db as $data) {

                // date status
                $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where('exam_id', $data->exam_id)->whereIn('class_id', $class_ids)->first();
                $starting_date = $starting_date_db['min(date)'];

                $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where('exam_id', $data->exam_id)->whereIn('class_id', $class_ids)->first();
                $ending_date = $ending_date_db['max(date)'];

                $currentTime = Carbon::now();
                $current_date = date($currentTime->toDateString());
                if ($current_date >= $starting_date && $current_date <= $ending_date) {
                    $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
                } elseif ($current_date < $starting_date) {
                    $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
                } else {
                    $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
                }

                // $request->status  =  0 :- all exams , 1 :- Upcoming , 2 :- On Going , 3 :- Completed

                if ($exam_status == 0) {
                    $exam_data[] = array(
                        'id' => $data->exam->id,
                        'name' => $data->exam->name,
                        'description' => $data->exam->description,
                        'publish' => $data->exam->publish,
                        'session_year' => $data->exam->session_year->name,
                        'exam_starting_date' => $starting_date,
                        'exam_ending_date' => $ending_date,
                        'exam_status' => $exam_status,
                        'class_id' => $data->class_id,
                        'class_name' => $data->class->name . '-' . $data->class->medium->name,
                        'class_streams' => $data->class->streams->name ?? null,
                        'exam_timetable' => $data->exam->timetable,
                    );
                }
            }

            if (!empty($exam_data)) {
                usort($exam_data, function ($a, $b) {
                    return strtotime($a['exam_starting_date']) - strtotime($b['exam_starting_date']);
                });
            }

            $events = Event::where('start_date', '>=', $current_date)->limit(5)->latest()->get();

            foreach ($events as $event) {
                if ($event->type == 'multiple') {
                    $hasdaySchedule = MultipleEvent::where('event_id', $event->id)->first();
                    if ($hasdaySchedule) {
                        $eventsList[] = [
                            'id' => $event->id,
                            'has_day_schedule' => 1,
                            'title' => $event->title,
                            'type' => $event->type,
                            'start_date' => $event->start_date,
                            'end_date' => $event->end_date,
                            'start_time' => $event->start_time,
                            'end_time' => $event->end_time,
                            'image' => $event->image,
                            'description' => $event->description,
                        ];
                    } else {
                        $eventsList[] = [
                            'id' => $event->id,
                            'has_day_schedule' => 0,
                            'title' => $event->title,
                            'type' => $event->type,
                            'start_date' => $event->start_date,
                            'end_date' => $event->end_date,
                            'start_time' => $event->start_time,
                            'end_time' => $event->end_time,
                            'image' => $event->image,
                            'description' => $event->description,
                        ];
                    }
                } else {
                    $eventsList[] = [
                        'id' => $event->id,
                        'has_day_schedule' => 0,
                        'title' => $event->title,
                        'type' => $event->type,
                        'start_date' => $event->start_date,
                        'end_date' => $event->end_date,
                        'start_time' => $event->start_time,
                        'end_time' => $event->end_time,
                        'image' => $event->image,
                        'description' => $event->description,
                    ];
                }
            }

            $staff_leave_requests = Leave::where('status', 1)
                ->where('to_date', '>=', Carbon::now()->format('Y-m-d'))
                ->where('user_id', '!=', $user_id)
                ->with(['leave_detail', 'user.roles'])
                ->orderBy('id', 'DESC')
                ->get();

            // Group leave requests by date categories
            $today = Carbon::now()->format('Y-m-d');
            $tomorrow = Carbon::now()->addDay()->format('Y-m-d');
            $upcoming_date = Carbon::now()->addDays(1)->format('Y-m-d');

            // Filter leave requests into categories
            $staff_leave_data = [
                'today' => [],
                'tomorrow' => [],
                'upcoming' => []
            ];

            foreach ($staff_leave_requests as $leaveRequest) {
                foreach ($leaveRequest->leave_detail as $detail) {

                    $leaveInfo = [
                        'user_name' => $leaveRequest->user->full_name,
                        'image' => $leaveRequest->user->image,
                        'date' => date('d-m-Y', strtotime($detail->date)),
                        'role' => $leaveRequest->user->roles->pluck('name')->implode(', '),
                        'type' => $detail->type
                    ];

                    // dd($leaveInfo);
                    if ($detail->date == $today) {
                        $staff_leave_data['today'][] = $leaveInfo;
                    } elseif ($detail->date == $tomorrow) {
                        $staff_leave_data['tomorrow'][] = $leaveInfo;
                    } elseif ($detail->date > $upcoming_date) {
                        $staff_leave_data['upcoming'][] = $leaveInfo;
                    }
                }
            }

            $staff_leave_data['upcoming'] = collect($staff_leave_data['upcoming'])
                ->sortBy(function ($item) {
                    return [$item['date'], $item['user_name']];
                })
                ->values()
                ->toArray();

            // dd($staff_leave_data);
            $data = [
                'class_teacher' => $class_teacher ?? [],
                'other_classes' => $class_section ?? [],
                'student_leave_request' => $student_leave_request ?? [],
                'timetable'   =>  $timetable ?? [],
                'upcoming_exams' => isset($exam_data) ? $exam_data : [],
                'staff_leaves' => $staff_leave_data ?? [],
                'events' => $eventsList ?? []
            ];
            ResponseService::successResponse('Data Fetched Successfully', $data ?? []);
        } catch (\Exception $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }

    public function updateTimetableLink(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'timetable_id'  => 'required',
            'live_class_link' => 'nullable|url',
            'link_name' => 'nullable',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $timetable = Timetable::where('id', $request->timetable_id)->first();
            $timetable->live_class_url = $request->live_class_link;
            $timetable->link_name =  $request->link_name;
            $timetable->save();
            ResponseService::successResponse('data_update_successfully');
        } catch (\Throwable $e) {
            ResponseService::errorResponse('error_occurred', null, 103, $e);
        }
    }
}
