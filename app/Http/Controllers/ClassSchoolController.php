<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Lesson;
use App\Models\Stream;
use App\Models\Mediums;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\Students;
use App\Models\ExamClass;
use App\Models\FeesClass;
use App\Models\Timetable;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\ExamResult;
use App\Models\OnlineExam;
use App\Models\ClassSchool;
use App\Http\Resources\User;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use Illuminate\Http\Request;
use App\Models\SubjectTeacher;
use App\Models\ElectiveSubject;
use App\Models\StudentSessions;
use App\Models\EducationalProgram;
use App\Models\OnlineExamQuestion;
use Illuminate\Support\Facades\DB;
use App\Models\ElectiveSubjectGroup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\ClassSubjectCollection;
use App\Models\StudentSubject;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ClassSchoolController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (!Auth::user()->can('class-list')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $classes = ClassSchool::orderBy('id', 'DESC')->with('medium', 'sections', 'streams')->get();
        $sections = Section::orderBy('id', 'ASC')->get();
        $mediums = Mediums::orderBy('id', 'ASC')->get();
        $streams = Stream::orderBy('id', 'ASC')->get();
        $shifts = Shift::where('status', 1)->get();
        $educational_programs = EducationalProgram::orderBy('id', 'ASC')->get();
        $semesters = Semester::orderBy('id', 'ASC')->get();
        return response(view('class.index', compact('classes', 'sections', 'mediums', 'streams', 'shifts', 'educational_programs', 'semesters')));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        if (!Auth::user()->can('class-create')) {
            $response = array(
                'error' => true,
                'message' => trans('no_permission_message')
            );
            return response()->json($response);
        }

        $validator = Validator::make($request->all(), [
            'medium_id' => 'required|numeric',
            'name' => 'required|regex:/^[A-Za-z0-9_]+$/',
            'section_id' => 'required',
        ]);

        if ($validator->fails()) {
            $response = array(
                'error' => true,
                'message' => $validator->errors()->first()
            );
            return response()->json($response);
        }
        try {
            if (!$request->stream_id) {
                $class = new ClassSchool();
                $class->name = $request->name;
                $class->educational_program_id = $request->educational_program;
                $class->medium_id = $request->medium_id;
                $class->shift_id = $request->shift_id;
                $class->include_semesters = $request->include_semesters ?? 0;
                $class->save();
                $class_section = array();
                foreach ($request->section_id as $section_id) {
                    $class_section[] = array(
                        'class_id' => $class->id,
                        'section_id' => $section_id
                    );
                }
                ClassSection::insert($class_section);
                $response = array(
                    'error' => false,
                    'message' => trans('data_store_successfully'),
                );
            } else {
                $classes = [];
                foreach ($request->stream_id as $stream_id) {
                    $classes[] = [
                        'name' => $request->name,
                        'medium_id' => $request->medium_id,
                        'stream_id' => $stream_id,
                        'shift_id'  => $request->shift_id,
                        'educational_program_id' => $request->educational_program,
                        'include_semesters' => $request->include_semesters ?? 0,
                    ];
                }

                $classIds = [];
                foreach ($classes as $class) {
                    $classIds[] = ClassSchool::insertGetId($class);
                }

                $class_sections = [];
                foreach ($classIds as $classId) {
                    foreach ($request->section_id as $section_id) {
                        $class_sections[] = [
                            'class_id' => $classId,
                            'section_id' => $section_id
                        ];
                    }
                }
                ClassSection::insert($class_sections);
                $response = array(
                    'error' => false,
                    'message' => trans('data_store_successfully'),
                );
            }
        } catch (\Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // dd($request->all());
        if (!Auth::user()->can('class-edit')) {
            $response = array(
                'error' => true,
                'message' => trans('no_permission_message')
            );
            return response()->json($response);
        }
        $validator = Validator::make($request->all(), [
            'medium_id' => 'required|numeric',
            'name' => 'required|regex:/^[A-Za-z0-9_]+$/',
            'section_id' => 'required',

        ]);

        if ($validator->fails()) {
            $response = array(
                'error' => true,
                'message' => $validator->errors()->first()
            );
            return response()->json($response);
        }
        try {
            $class = ClassSchool::find($id);

            $semesterIncluded = $request->include_semesters[0] ?? 0;
            if ($class->include_semesters != $semesterIncluded) {
                //If include_semester is changed then delete the class subjects
                $elective_subject_group = ElectiveSubjectGroup::where('class_id', $class->id)->delete();
                $class_subjects = ClassSubject::where('class_id', $class->id)->delete();
            }

            $class->name = $request->name;
            $class->educational_program_id = $request->educational_program;
            $class->medium_id = $request->medium_id;
            $class->shift_id = $request->shift_id;
            $class->include_semesters = $semesterIncluded;

            if ($request->stream_id != null) {
                $existingrow = ClassSchool::where('name', $request->name)->where('medium_id', $request->medium_id)->where('shift_id', $request->shift_id)->where('stream_id', $request->stream_id)->first();
                if ($existingrow) {
                    $existingrow->stream_id = $request->stream_id;
                } else {
                    $class->stream_id = $request->stream_id;
                }
            }
            $class->save();
            $all_section_ids = ClassSection::whereIn('section_id', $request->section_id)->where('class_id', $id)->pluck('section_id')->toArray();
            $delete_class_section = $class->sections->pluck('id')->toArray();
            $class_section = array();
            foreach ($request->section_id as $key => $section_id) {
                if (!in_array($section_id, $all_section_ids)) {
                    $class_section[] = array(
                        'class_id' => $class->id,
                        'section_id' => $section_id
                    );
                } else {
                    unset($delete_class_section[array_search($section_id, $delete_class_section)]);
                }
            }
            ClassSection::insert($class_section);

            // check wheather the id in $delete_class_section is assosiated with other data ..
            $assignemnts = Assignment::whereIn('class_section_id', $delete_class_section)->count();
            $attendances = Attendance::whereIn('class_section_id', $delete_class_section)->count();
            $exam_result = ExamResult::whereIn('class_section_id', $delete_class_section)->count();
            $lessons = Lesson::whereIn('class_section_id', $delete_class_section)->count();
            $student_session = StudentSessions::whereIn('class_section_id', $delete_class_section)->count();
            $students = Students::whereIn('class_section_id', $delete_class_section)->count();
            $subject_teachers = SubjectTeacher::whereIn('class_section_id', $delete_class_section)->count();
            $timetables = Timetable::whereIn('class_section_id', $delete_class_section)->count();

            if ($assignemnts || $attendances || $exam_result || $lessons || $student_session || $students || $subject_teachers || $timetables) {
                $response = array(
                    'error' => true,
                    'message' => trans('cannot_delete_beacuse_data_is_associated_with_other_data')
                );
                return response()->json($response);
            } else {
                //Remaining Data in $delete_class_section should be deleted
                ClassSection::whereIn('section_id', $delete_class_section)->where('class_id', $id)->delete();
            }

            $response = array(
                'error' => false,
                'message' => trans('data_update_successfully'),
            );
        } catch (\Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e
            );
        }
        return response()->json($response);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\ClassSchool $classSchool
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        if (!Auth::user()->can('class-delete')) {
            $response = array(
                'error' => true,
                'message' => trans('no_permission_message')
            );
            return response()->json($response);
        }
        try {
            // check wheather the class exists in other table
            $class_subject = ClassSubject::where('class_id', $id)->count();
            $class_exam = ExamClass::where('class_id', $id)->count();
            $class_fees = FeesClass::where('class_id', $id)->count();

            if ($class_subject || $class_exam || $class_fees) {
                $response = array(
                    'error' => true,
                    'message' => trans('cannot_delete_beacuse_data_is_associated_with_other_data')
                );
            } else {
                $class = ClassSchool::find($id);
                $class_section = ClassSection::where('class_id', $class->id);

                // check the class section id exists with other table ...
                $class_section_id = $class_section->pluck('id');
                $assignemnts = Assignment::whereIn('class_section_id', $class_section_id)->count();
                $attendances = Attendance::whereIn('class_section_id', $class_section_id)->count();
                $exam_result = ExamResult::whereIn('class_section_id', $class_section_id)->count();
                $lessons = Lesson::whereIn('class_section_id', $class_section_id)->count();
                $student_session = StudentSessions::whereIn('class_section_id', $class_section_id)->count();
                $students = Students::whereIn('class_section_id', $class_section_id)->count();
                $subject_teachers = SubjectTeacher::whereIn('class_section_id', $class_section_id)->count();
                $timetables = Timetable::whereIn('class_section_id', $class_section_id)->count();

                if ($assignemnts || $attendances || $exam_result || $lessons || $student_session || $students || $subject_teachers || $timetables) {
                    $response = array(
                        'error' => true,
                        'message' => trans('cannot_delete_beacuse_data_is_associated_with_other_data')
                    );
                } else {
                    $class_section->delete();
                    $class->delete();
                    $response = array(
                        'error' => false,
                        'message' => trans('data_delete_successfully')
                    );
                }
            }
        } catch (\Throwable $e) {
            $message = trans('error_occurred');
            $err = strtolower($e->getMessage());
            if (str_contains($err, 'integrity constraint') || str_contains($err, 'foreign key') || str_contains($err, 'constraint')) {
                $message = trans('cannot_delete_beacuse_data_is_associated_with_other_data');
            }
            $response = array(
                'error' => true,
                'message' => $message
            );
        }
        return response()->json($response);
    }

    public function show()
    {
        if (!Auth::user()->can('class-list')) {
            $response = array(
                'error' => true,
                'message' => trans('no_permission_message')
            );
            return response()->json($response);
        }
        $offset = 0;
        $limit = 10;
        $sort = 'id';
        $order = 'DESC';

        if (isset($_GET['offset']))
            $offset = $_GET['offset'];
        if (isset($_GET['limit']))
            $limit = $_GET['limit'];

        if (isset($_GET['sort']))
            $sort = $_GET['sort'];
        if (isset($_GET['order']))
            $order = $_GET['order'];
        DB::enableQueryLog();
        $sql = ClassSchool::with('sections', 'medium', 'streams', 'shifts', 'educational_program');
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $search = $_GET['search'];
            $sql->where('id', 'LIKE', "%$search%")->orwhere('name', 'LIKE', "%$search%")
                ->orWhereHas('sections', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%$search%");
                })->orWhereHas('medium', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%$search%");
                })->orWhereHas('streams', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%$search%");
                })->orWhereHas('shifts', function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%$search%");
                })->orWhereHas('educational_program', function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%$search%");
                });
        }
        if ($_GET['medium_id']) {
            $sql = $sql->where('medium_id', $_GET['medium_id']);
        }
        if ($_GET['shift_id']) {
            $sql = $sql->where('shift_id', $_GET['shift_id']);
        }

        if ($_GET['educational_program_id']) {
            $sql = $sql->where('educational_program_id', $_GET['educational_program_id']);
        }
        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $tempRow = array();
        $no = 1;
        foreach ($res as $row) {
            $operate = '<a href=' . route('class.edit', $row->id) . ' class="btn btn-xs btn-gradient-primary btn-rounded btn-icon edit-data" data-id=' . $row->id . ' title="Edit" data-toggle="modal" data-target="#editModal"><i class="fa fa-edit"></i></a>&nbsp;&nbsp;';
            $operate .= '<a href=' . route('class.destroy', $row->id) . ' class="btn btn-xs btn-gradient-danger btn-rounded btn-icon delete-form" data-id=' . $row->id . '><i class="fa fa-trash"></i></a>';

            $tempRow['id'] = $row->id;
            $tempRow['no'] = $no++;
            $tempRow['name'] = $row->name;
            $tempRow['educational_program_id'] = $row->educational_program->id ?? '';
            $tempRow['educational_program_name'] = $row->educational_program->title ?? '-';
            $tempRow['medium_id'] = $row->medium->id;
            $tempRow['medium_name'] = $row->medium->name;
            $tempRow['shift_id'] = $row->shifts->id ?? '';
            $tempRow['shift_name'] = $row->shifts->title ?? '-';
            $sections = $row->sections;
            $tempRow['section_id'] = $sections->pluck('id');
            $tempRow['section_name'] = $sections->pluck('name');
            $tempRow['stream_id'] = $row->streams->id ?? '';
            $tempRow['stream_name'] = $row->streams->name ?? '-';
            $tempRow['include_semesters'] = $row->include_semesters;
            $tempRow['created_at'] = convertDateFormat($row->created_at, 'd-m-Y H:i:s');
            $tempRow['updated_at'] = convertDateFormat($row->created_at, 'd-m-Y H:i:s');
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }
    public function subject()
    {
        if (!Auth::user()->can('class-list')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }

        $classes = ClassSchool::orderBy('id', 'DESC')->with('medium', 'sections', 'streams')->get();
        $subjects = Subject::orderBy('id', 'ASC')->get();
        $mediums = Mediums::orderBy('id', 'ASC')->get();
        $streams = Stream::orderBy('id', 'ASC')->get();
        $semesters = Semester::orderBy('id', 'ASC')->get();

        return response(view('class.subject', compact('classes', 'subjects', 'mediums', 'streams', 'semesters')));
    }

    public function update_subjects(Request $request)
    {
        $validation_rules = array(
            'class_id' => 'required|numeric',
            'edit_core_subject' => 'nullable|array',
            'edit_core_subject.*' => 'nullable|array|required_array_keys:class_subject_id,subject_id',
            'core_subjects' => 'nullable|array',
            'elective_subject_id' => 'array',
            'elective_subjects' => 'nullable|array',
            'elective_subjects.*.subject_id' => 'required|array',
            'elective_subjects.*.total_selectable_subjects' => 'required|numeric',
        );

        $validator = Validator::make($request->all(), $validation_rules);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first()
            ]);
        }

        try {

            /*
        |--------------------------------------------------------------------------
        | UPDATE CORE SUBJECTS
        |--------------------------------------------------------------------------
        */

            if ($request->edit_core_subject) {
                foreach ($request->edit_core_subject as $row) {
                    $edit_core_subject = ClassSubject::findOrFail($row['class_subject_id']);
                    $edit_core_subject->subject_id = $row['subject_id'];
                    $edit_core_subject->semester_id = $row['semester_id'] ?? null;
                    $edit_core_subject->save();
                }
            }

            /*
        |--------------------------------------------------------------------------
        | ADD NEW CORE SUBJECTS
        |--------------------------------------------------------------------------
        */

            if ($request->core_subjects) {
                $core_subjects = [];
                foreach ($request->core_subjects as $row) {
                    $core_subjects[] = [
                        'class_id' => $request->class_id,
                        'type' => "Compulsory",
                        'subject_id' => $row['subject_id'],
                        'semester_id' => $row['semester_id'] ?? null,
                    ];
                }
                ClassSubject::insert($core_subjects);
            }

            /*
        |--------------------------------------------------------------------------
        | EDIT ELECTIVE SUBJECT GROUPS + CLEANUP STUDENT SUBJECTS
        |--------------------------------------------------------------------------
        */

            if ($request->edit_elective_subjects) {
                foreach ($request->edit_elective_subjects as $subject_group) {

                    // --- FETCH OLD SUBJECT IDS BEFORE UPDATE ---
                    $old_subject_ids = ClassSubject::where('elective_subject_group_id', $subject_group['subject_group_id'])
                        ->pluck('subject_id');

                    // --- UPDATE ELECTIVE GROUP ---
                    $elective_subject_group = ElectiveSubjectGroup::findOrFail($subject_group['subject_group_id']);
                    $elective_subject_group->total_subjects = count($subject_group['subject_id']);
                    $elective_subject_group->total_selectable_subjects = $subject_group['total_selectable_subjects'];
                    $elective_subject_group->class_id = $request->class_id;
                    $elective_subject_group->semester_id = $subject_group['semester_id'] ?? null;
                    $elective_subject_group->save();

                    // --- UPDATE SUBJECTS INSIDE THE GROUP ---
                    $new_subject_ids = collect($subject_group['subject_id']);

                    foreach ($subject_group['subject_id'] as $key => $subject_id) {

                        if (!empty($subject_group['class_subject_id'][$key])) {
                            // Existing subject
                            $elective_subject = ClassSubject::findOrFail($subject_group['class_subject_id'][$key]);
                        } else {
                            // New subject
                            $elective_subject = new ClassSubject();
                        }

                        $elective_subject->class_id = $request->class_id;
                        $elective_subject->type = "Elective";
                        $elective_subject->subject_id = $subject_id;
                        $elective_subject->elective_subject_group_id = $elective_subject_group->id;
                        $elective_subject->semester_id = $subject_group['semester_id'] ?? null;
                        $elective_subject->save();
                    }

                    // --- CLEANUP: REMOVE STUDENT SUBJECTS FOR REMOVED ELECTIVE SUBJECTS ---
                    $removed_subject_ids = $old_subject_ids->diff($new_subject_ids);

                    if ($removed_subject_ids->count()) {

                        // find all class sections for this class
                        $class_section_ids = ClassSection::where('class_id', $request->class_id)->pluck('id');

                        // delete from student_subjects
                        StudentSubject::whereIn('subject_id', $removed_subject_ids)
                            ->whereIn('class_section_id', $class_section_ids)
                            ->when($subject_group['semester_id'] ?? null, function ($query, $semester) {
                                return $query->where('semester_id', $semester);
                            })
                            ->delete();
                    }
                }
            }

            /*
        |--------------------------------------------------------------------------
        | CREATE NEW ELECTIVE SUBJECT GROUPS
        |--------------------------------------------------------------------------
        */

            if ($request->elective_subjects) {
                foreach ($request->elective_subjects as $subject_group) {

                    // Create group
                    $elective_subject_group = new ElectiveSubjectGroup();
                    $elective_subject_group->total_subjects = count($subject_group['subject_id']);
                    $elective_subject_group->total_selectable_subjects = $subject_group['total_selectable_subjects'];
                    $elective_subject_group->class_id = $request->class_id;
                    $elective_subject_group->semester_id = $subject_group['semester_id'] ?? null;
                    $elective_subject_group->save();

                    foreach ($subject_group['subject_id'] as $subject_id) {
                        $elective_subject = array(
                            'class_id' => $request->class_id,
                            'type' => "Elective",
                            'subject_id' => $subject_id,
                            'semester_id' => $subject_group['semester_id'] ?? null,
                            'elective_subject_group_id' => $elective_subject_group->id,
                        );
                        ClassSubject::insert($elective_subject);
                    }
                }
            }

            return response()->json([
                'error' => false,
                'message' => trans('data_store_successfully'),
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e->getMessage()
            ]);
        }
    }



    public function subject_list()
    {
        if (!Auth::user()->can('class-list')) {
            return response()->json([
                'error' => true,
                'message' => trans('no_permission_message')
            ]);
        }

        $offset = $_GET['offset'] ?? 0;
        $limit = $_GET['limit'] ?? 10;
        $sort = $_GET['sort'] ?? 'id';
        $order = $_GET['order'] ?? 'DESC';

        $sql = ClassSchool::with('sections', 'medium', 'streams', 'coreSubject.semester', 'electiveSubjectGroup.electiveSubjects.subject');

        if (!empty($_GET['search'])) {
            $search = $_GET['search'];
            $sql->where('id', 'LIKE', "%$search%")
                ->orWhere('name', 'LIKE', "%$search%");
        }

        if (!empty($_GET['medium_id'])) {
            $sql->where('medium_id', $_GET['medium_id']);
        }

        $total = $sql->count();

        $currentSemester = Semester::get()->first(function ($semester) {
            return $semester->current;
        });

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '<a href=' . route('class-subject-edit.index', $row->id) . ' class="btn btn-xs btn-gradient-primary btn-rounded btn-icon edit-data" data-id=' . $row->id . ' title="Edit"><i class="fa fa-edit"></i></a>&nbsp;&nbsp;';

            $tempRow = [];
            $tempRow['id'] = $row->id;
            $tempRow['no'] = $no++;
            $tempRow['name'] = $row->name;
            $tempRow['medium_id'] = $row->medium->id;
            $tempRow['medium_name'] = $row->medium->name;
            $tempRow['stream_id'] = $row->streams->id ?? ' ';
            $tempRow['stream_name'] = $row->streams->name ?? '-';
            $tempRow['section_names'] = $row->sections->pluck('name');
            $tempRow['include_semesters'] = $row->include_semesters;
            $tempRow['semesters'] = Semester::all(); // List all semesters

            if ($row->include_semesters && !empty($currentSemester)) {
                $tempRow['core_subjects'] = $row->coreSubject->filter(function ($data) use ($currentSemester) {
                    return $data->semester_id == $currentSemester->id;
                })->values(); // Filter subjects based on current semester

                $tempRow['elective_subject_groups'] = $row->electiveSubjectGroup->filter(function ($data) use ($currentSemester) {
                    return $data->semester_id == $currentSemester->id;
                })->values();
            } else {
                $tempRow['core_subjects'] = $row->coreSubject;
                $tempRow['elective_subject_groups'] = $row->electiveSubjectGroup;
            }

            $tempRow['created_at'] = convertDateFormat($row->created_at, 'd-m-Y H:i:s');
            $tempRow['updated_at'] = convertDateFormat($row->updated_at, 'd-m-Y H:i:s');
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }


    public function subject_destroy($id)
    {
        // if (!Auth::user()->can('class-delete')) {
        //     $response = array(
        //         'error' => true,
        //         'message' => trans('no_permission_message')
        //     );
        //     return response()->json($response);
        // }
        try {
            //check wheather the class subject exists in other table
            $online_exam_questions = OnlineExamQuestion::where('class_subject_id', $id)->count();
            $online_exams = OnlineExam::where('subject_id', $id)->count();
            if ($online_exam_questions || $online_exams) {
                $response = array(
                    'error' => true,
                    'message' => trans('cannot_delete_beacuse_data_is_associated_with_other_data')
                );
            } else {
                $class_subject = ClassSubject::findOrFail($id);
                if ($class_subject->type == "Elective") {
                    $subject_group = ElectiveSubjectGroup::findOrFail($class_subject->elective_subject_group_id);
                    $subject_group->total_subjects = $subject_group->total_subjects - 1;
                    if ($subject_group->total_subjects > 0) {
                        $subject_group->save();
                    } else {
                        $subject_group->delete();
                    }
                }
                $class_subject->delete();
                $response = array(
                    'error' => false,
                    'message' => trans('data_delete_successfully')
                );
            }
        } catch (\Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred')
            );
        }
        return response()->json($response);
    }

    public function subject_group_destroy($id)
    {
        try {
            $subject_group = ElectiveSubjectGroup::findOrFail($id);

            // 1. Check if the group is used in Online Exam tables
            $class_subject_ids = ClassSubject::where('elective_subject_group_id', $id)->pluck('id');

            $online_exam_questions = OnlineExamQuestion::whereIn('class_subject_id', $class_subject_ids)->count();
            $online_exams = OnlineExam::whereIn('subject_id', $class_subject_ids)->count();

            if ($online_exam_questions || $online_exams) {
                return response()->json([
                    'error' => true,
                    'message' => trans('cannot_delete_beacuse_data_is_associated_with_other_data')
                ]);
            }

            // 2. Find all class_section_ids for this class
            $class_section_ids = ClassSection::where('class_id', $subject_group->class_id)
                ->pluck('id');

            // 3. Find elective subject_ids under this group
            $elective_subject_ids = $subject_group->electiveSubjects()->pluck('subject_id');

            // 4. Delete related student_subjects rows
            StudentSubject::whereIn('subject_id', $elective_subject_ids)
                ->whereIn('class_section_id', $class_section_ids)
                ->delete();

            // 5. Delete elective subjects under this group
            $subject_group->electiveSubjects()->delete();

            // 6. Delete the group itself
            $subject_group->delete();

            return response()->json([
                'error' => false,
                'message' => trans('data_delete_successfully')
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'error' => true,
                'message' => trans('error_occurred')
            ]);
        }
    }


    public function getSubjectsByMediumId($medium_id)
    {
        try {
            $subjects = Subject::where('medium_id', $medium_id)->get();
            $response = array(
                'error' => false,
                'data' => $subjects,
                'message' => trans('data_delete_successfully')
            );
        } catch (\Throwable $e) {
            $response = array(
                'error' => true,
                'message' => trans('error_occurred')
            );
        }
        return response()->json($response);
    }

    public function classSubjectsEdit($id)
    {
        if (!Auth::user()->can('class-list')) {
            $response = array(
                'message' => trans('no_permission_message')
            );
            return redirect(route('home'))->withErrors($response);
        }
        $class = ClassSchool::where('id', $id)->orderBy('id', 'DESC')->with('medium', 'sections', 'streams', 'coreSubject', 'electiveSubjectGroup.electiveSubjects')->first();
        $semesters = Semester::orderBy('id', 'ASC')->get();
        $subjects = Subject::orderBy('id', 'ASC')->get();
        $mediums = Mediums::orderBy('id', 'ASC')->get();
        $streams = Stream::orderBy('id', 'ASC')->get();
        // dd($class->toArray());
        return response(view('class.edit_subject', compact('class', 'semesters', 'subjects', 'mediums', 'streams',)));
    }

    public function assignElectiveSubject(Request $request)
    {
        try {
            if (!Auth::user()->can('assign-elective-subjects')) {
                return response()->json([
                    'error' => true,
                    'message' => trans('no_permission_message')
                ]);
            }

            // Validation
            $validator = Validator::make($request->all(), [
                'student_id' => 'required_if:is_bulk,0|exists:students,id',
                'student_ids' => 'required_if:is_bulk,1|array',
                'student_ids.*' => 'exists:students,id',
                'elective_group' => 'required|exists:elective_subject_groups,id',
                'selected_subjects' => 'required|array',
                'selected_subjects.*' => 'required|array|min:1',
                'is_bulk' => 'nullable|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => $validator->errors()->first()
                ], 422);
            }

            $isBulk = (int)$request->input('is_bulk', 0);
            $electiveGroupId = $request->input('elective_group');
            $selectedSubjects = $request->input('selected_subjects');

            // Flatten all selected subject IDs into one array
            $selectedSubjectIds = collect($selectedSubjects)->flatten()->toArray();

            $electiveGroup = ElectiveSubjectGroup::with('electiveSubjects')->findOrFail($electiveGroupId);

            $selectedCount = count($selectedSubjectIds);
            $requiredCount = $electiveGroup->total_selectable_subjects;

            if ($selectedCount != $requiredCount) {
                return response()->json([
                    'error' => true,
                    'message' => "You must select exactly {$requiredCount} subject(s)."
                ], 422);
            }

            $validSubjectIds = $electiveGroup->electiveSubjects->pluck('subject_id')->toArray();
            $invalidSubjects = array_diff($selectedSubjectIds, $validSubjectIds);

            if (!empty($invalidSubjects)) {
                return response()->json([
                    'error' => true,
                    'message' => 'Some selected subjects do not belong to this elective group.'
                ], 422);
            }

            // Get session year and current semester
            $sessionYearData = getSettings('session_year');
            $sessionYearId = is_array($sessionYearData)
                ? ($sessionYearData['session_year'] ?? $sessionYearData['id'] ?? null)
                : ($sessionYearData->session_year ?? $sessionYearData->id ?? null);

            $currentSemester = Semester::get()->first(function ($semester) {
                return $semester->current;
            });

            // Build list of students (single or bulk)
            $studentIds = $isBulk ? $request->input('student_ids') : [$request->input('student_id')];

            DB::beginTransaction();

            foreach ($studentIds as $studentId) {
                $student = Students::findOrFail($studentId);

                // Delete existing elective subjects from this same elective group
                StudentSubject::where('student_id', $studentId)
                    ->where('class_section_id', $student->class_section_id)
                    ->whereIn('subject_id', $validSubjectIds)
                    ->delete();

                // Assign new elective subjects
                foreach ($selectedSubjectIds as $subjectId) {
                    StudentSubject::create([
                        'student_id' => $studentId,
                        'subject_id' => $subjectId,
                        'class_section_id' => $student->class_section_id,
                        'session_year_id' => $sessionYearId,
                        'semester_id' => $currentSemester?->id,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'error' => false,
                'message' => $isBulk
                    ? 'Elective subjects assigned successfully to all selected students!'
                    : 'Elective subjects assigned successfully!'
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getStudentAssignedSubjects(Request $request)
    {
        try {
            if (!Auth::user()->can('assign-elective-subjects')) {
                return response()->json([
                    'error' => true,
                    'message' => trans('no_permission_message')
                ]);
            }

            $studentId = $request->input('student_id');
            // $electiveGroupId = $request->input('elective_group_id');

            if (!$studentId) {
                return response()->json(['error' => 'Student ID is required'], 400);
            }

            $student = Students::findOrFail($studentId);

            // Get assigned elective subjects for this student
            $assignedSubjects = StudentSubject::where('student_id', $studentId)
                ->where('class_section_id', $student->class_section_id)
                ->pluck('subject_id')
                ->toArray();

            return response()->json([
                'error' => false,
                'assigned_subjects' => $assignedSubjects
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function selectElectiveSubjects()
    {
        if (!Auth::user()->can('assign-elective-subjects')) {
            $response = [
                'message' => trans('no_permission_message')
            ];
            return redirect(route('home'))->withErrors($response);
        }

        $classes = [];

        if (Auth::user()->hasRole('Super Admin')) {
            // Get unique classes, with required relations
            $classes = ClassSchool::with([
                'medium',
                'streams'
            ])->get();
        }

        // Students will depend on selected class_section, so keep it empty for now
        $elective_subjects = [];

        return view(
            'select_elective_subjects.index',
            compact('classes', 'elective_subjects')
        );
    }

    public function getElectiveGroups(Request $request)
    {
        if (!Auth::user()->can('assign-elective-subjects')) {
            return response()->json([
                'error' => true,
                'message' => trans('no_permission_message')
            ]);
        }

        $class_id = $request->class_id;
        $groups = ElectiveSubjectGroup::with([
            'electiveSubjects' => function ($query) {
                $query->without('semester')->with('subject');
            }
        ])
            ->where('class_id', $class_id)
            ->get()
            ->values();

        // Return as JSON for AJAX
        return response()->json($groups);
    }

    public function getElectiveSubjects(Request $request)
    {
        if (!Auth::user()->can('assign-elective-subjects')) {
            return response()->json([
                'error' => true,
                'message' => trans('no_permission_message')
            ]);
        }

        $class_id = $request->class_id;

        // Fetch elective subjects corresponding to class 
        $subjects = ElectiveSubjectGroup::with([
            'electiveSubjects' => function ($query) {
                $query->without('semester')->with('subject');
            }
        ])
            ->where('class_id', $class_id)
            ->get()
            ->pluck('electiveSubjects')
            ->flatten()
            ->pluck('subject')
            ->filter()
            ->values();

        // Return as JSON for AJAX
        return response()->json($subjects);
    }

    public function fetchStudentSubjects(Request $request)
    {
        if (!Auth::user()->can('assign-elective-subjects')) {
            return response()->json([
                'error'   => true,
                'message' => trans('no_permission_message')
            ]);
        }

        $offset   = (int) $request->input('offset', 0);
        $limit    = (int) $request->input('limit', 10);
        $sort     = $request->input('sort', 'id');
        $order    = $request->input('order', 'DESC');
        $search   = $request->input('search');
        $status   = $request->input('filter_status');

        // Get session year
        $sessionYearData = getSettings('session_year');
        $sessionYearId = is_array($sessionYearData)
            ? ($sessionYearData['session_year'] ?? $sessionYearData['id'] ?? null)
            : ($sessionYearData->session_year ?? $sessionYearData->id ?? null);

        // ---------- NEW: single class_id from request ----------
        $class_id = (int) $request->input('class_id');
        if (!$class_id) {
            return response()->json([
                'total' => 0,
                'rows'  => []
            ]);
        }
        // -------------------------------------------------------

        // Single elective subject filter (e.g., filter_elective_subject=6)
        $filterSubjectId = $request->filled('filter_elective_subject')
            ? (int) $request->input('filter_elective_subject')
            : null;

        /* -------------------------------------------------------
       1. Get **all students** that belong to the given class
       ------------------------------------------------------- */
        $query = Students::with(['user', 'class_section.class', 'class_section.section'])
            ->leftJoin('users', 'students.user_id', '=', 'users.id')
            ->select('students.*')
            ->join('class_sections', 'students.class_section_id', '=', 'class_sections.id')
            ->where('class_sections.class_id', $class_id)
            ->when(
                $search,
                fn($q) => $q
                    ->whereRaw("CONCAT(users.first_name,' ',users.last_name) LIKE ?", ["%$search%"])
                    ->orWhere('students.admission_no', 'LIKE', "%$search%")
            );

        $students = $query->orderBy($sort, $order)->get();

        /* -------------------------------------------------------
       2. Rules, elective IDs and current selections for THIS class only
       ------------------------------------------------------- */
        $rule = ElectiveSubjectGroup::where('class_id', $class_id)->first();

        $electiveIds = ClassSubject::where('class_id', $class_id)
            ->where('type', 'Elective')
            ->pluck('subject_id')
            ->toArray();

        $selections = StudentSubject::whereIn('student_id', $students->pluck('id'))
            ->whereIn('subject_id', $electiveIds)
            ->whereIn('class_section_id', $students->pluck('class_section_id'))
            ->where('session_year_id', $sessionYearId)
            ->select('student_id', 'subject_id')
            ->get()
            ->groupBy('student_id');

        $filtered = [];

        foreach ($students as $s) {
            $st = $this->calcStatus($s, collect([$class_id => $rule]), collect([$class_id => $electiveIds]), $selections);

            // ---- status filter -------------------------------------------------
            if ($status && $st['label'] !== $this->map($status)) {
                continue;
            }

            // ---- elective-subject filter ----------------------------------------
            if ($filterSubjectId) {
                $studentElectiveIds = $selections->get($s->id, collect())
                    ->pluck('subject_id')
                    ->toArray();

                if (!in_array($filterSubjectId, $studentElectiveIds)) {
                    continue;
                }
            }

            // ---- selected subject names -----------------------------------------
            $names = $selections->get($s->id, collect())
                ->map(fn($x) => Subject::find($x->subject_id)?->name ?? '')
                ->filter()
                ->implode(', ');

            $filtered[] = [
                'id'                => $s->id,
                'full_name'         => $s->user->full_name,
                'photo'             => $s->user->image,
                'status'            => $st['label'],
                'status_badge'      => $st['badge'],
                'selected_subjects' => $names ?: '—',
                'class_id'          => $class_id,
                'operate'           => '<a href="javascript:void(0)" class="btn btn-xs border border-dark rounded btn-secondary bg-transparent text-dark assign-elective" 
                    data-class-id="' . $class_id . '" data-student-id="' . $s->id . '" 
                    data-toggle="modal" data-target="#assignModal">Assign</a>',
            ];
        }

        $total = count($filtered);
        $rows  = array_slice($filtered, $offset, $limit);

        // ---- KEEP ORIGINAL RESPONSE STRUCTURE ----
        return response()->json([
            'total' => $total,
            'rows'  => $rows,
        ]);
    }

    private function map($key)
    {
        return ['not_assigned' => 'Not Assigned', 'incomplete' => 'Incomplete', 'complete' => 'Completed'][$key] ?? '';
    }

    private function calcStatus(
        $student,
        $rules,
        $electiveIds,
        $selections
    ): array {
        // Defensive normalization: accept arrays or Collections
        $rules = collect($rules); // keyed by class_id => rule-object/array
        $electiveIds = collect($electiveIds)
            ->map(function ($ids) {
                // ensure every value is an array of IDs
                return is_array($ids) ? array_values($ids) : [(int)$ids];
            });
        $selections = collect($selections)
            ->map(function ($items) {
                // ensure each student's selections is a Collection
                return collect($items);
            });

        // Get class id from student (null-safe)
        $classId = $student->class_section?->class_id ?? null;
        if (!$classId) {
            return [
                'label'    => 'No Class',
                'badge'    => '<span class="badge badge-secondary">N/A</span>',
                'selected' => 0,
                'required' => 0,
            ];
        }

        // Lookup rule for this class
        $rule = $rules->get($classId);
        $requiredCount = $rule->total_selectable_subjects ?? 0;

        // Get student's selections (collection) and elective subject ids for the class
        $studentSelections = $selections->get($student->id, collect());
        $validElectiveIds = $electiveIds->get($classId, []);

        // Ensure IDs are integers (defensive) and always array
        $validElectiveIds = array_map('intval', (array)$validElectiveIds);

        // Count how many selected subjects are actually elective for this class
        $selectedCount = $studentSelections->whereIn('subject_id', $validElectiveIds)->count();

        // Clear, separate conditions for easier reasoning & debugging:
        if ($requiredCount === 0) {
            // No rule exists (class doesn't require/select electives)
            return [
                'label'    => 'No Rule',
                'badge'    => '<span class="badge badge-info">No Rule</span>',
                'selected' => $selectedCount,
                'required' => $requiredCount,
            ];
        }
        if ($selectedCount === 0) {
            // Rule exists but student hasn't selected any valid elective
            return [
                'label'    => 'Not Assigned',
                'badge'    => '<span class="badge badge-secondary border rounded border-dark text-dark bg-transparent">Not Assigned</span>',
                'selected' => 0,
                'required' => $requiredCount,
            ];
        }

        if ($selectedCount >= $requiredCount) {
            return [
                'label'    => 'Completed',
                'badge'    => '<span class="badge badge-success border rounded border-success text-success bg-transparent">Completed</span>',
                'selected' => $selectedCount,
                'required' => $requiredCount,
            ];
        }

        return [
            'label'    => 'Incomplete',
            'badge'    => '<span class="badge badge-warning border rounded border-warning text-warning bg-transparent">Incomplete</span>',
            'selected' => $selectedCount,
            'required' => $requiredCount,
        ];
    }

    public function getClassSections($class_id)
    {
        $sections = ClassSection::with('class', 'section', 'streams')
            ->where('class_id', $class_id)
            ->get();

        return response()->json($sections);
    }
}
