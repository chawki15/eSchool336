<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use Carbon\Carbon;
use App\Models\Exam;
use App\Models\User;
use App\Models\Leave;
use App\Models\Parents;
use App\Models\Teacher;
use App\Models\Semester;
use App\Models\Settings;
use App\Models\Students;
use App\Models\Attendance;
use App\Models\ExamResult;
use App\Models\ClassSchool;
use App\Models\LeaveDetail;
use App\Models\Announcement;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\ClassTeacher;
use Illuminate\Http\Request;
use App\Models\SubjectTeacher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     */
    public function login(): RedirectResponse|View
    {
        if (Auth::user()) {
            return redirect('home');
        }
        
        return view('auth.login');
    }

    public function resetpassword(): View
    {
        return view('settings.reset_password');
    }

    public function checkPassword(Request $request): JsonResponse
    {
        $old_password = $request->input('old_password');
        $password = User::where('id', Auth::id())->first();
        
        $isValid = $password && Hash::check($old_password, $password->password);
        
        return response()->json($isValid ? 1 : 0);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $id = Auth::id();
        $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|min:8',
            'confirm_password' => 'required|same:new_password',
        ]);

        try {
            $data = ['password' => Hash::make($request->input('new_password'))];
            User::where('id', $id)->update($data);
            
            return response()->json([
                'error' => false,
                'message' => trans('data_update_successfully')
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => true,
                'message' => trans('error_occurred')
            ]);
        }
    }

    public function index(): View
    {
        $session_year = getSettings('session_year');
        $user = Auth::user();
        
        // Initialize variables
        $data = [
            'teacher' => null,
            'student' => null,
            'parent' => null,
            'teachers' => null,
            'class_sections' => null,
            'rankers' => null,
            'attendance' => null,
            'leaves' => null,
            'filter_upcoming' => 'Today',
            'boys' => 0,
            'girls' => 0,
            'offset' => 0,
            'limit' => 10,
        ];

        // Handle Super Admin specific data
        if ($user->hasRole('Super Admin')) {
            $data = array_merge($data, $this->getSuperAdminData());
        }

        // Handle Teacher specific data
        if ($user->hasRole('Teacher')) {
            $data['class_sections'] = $this->getTeacherClassSections($user->teacher->id);
        }

        // Common data for all users
        $data['date_format'] = "d-m-Y H:i:s";
        $data['announcement'] = Announcement::where('table_type', "")
            ->where('session_year_id', $session_year['session_year'])
            ->latest()
            ->limit(3)
            ->get();

        $data['attendance'] = Attendance::with('class_section')
            ->select('class_section_id', 'type', 'date', 
                DB::raw('COUNT(*) as total_attendance'),
                DB::raw('SUM(CASE WHEN type = 1 THEN 1 ELSE 0 END) as total_present')
            )
            ->groupBy('class_section_id')
            ->get();

        $data['leaves'] = $this->getUpcomingLeaves((int) $session_year['session_year']);

        return view('home', $data);
    }

    private function getSuperAdminData(): array
    {
        $teacher = Teacher::count();
        $student = Students::count();
        $parent = Parents::count();

        $teachers = Teacher::with('user:id,first_name,last_name,image')->get();
        
        $boys = 0;
        $girls = 0;
        
        if ($student > 0) {
            $boys_count = Students::whereHas('user', fn($query) => $query->where('gender', 'male'))->count();
            $girls_count = Students::whereHas('user', fn($query) => $query->where('gender', 'female'))->count();

            $boys = round((($boys_count * 100) / $student), 2);
            $girls = round(($girls_count * 100) / $student, 2);
        }

        $rankers = ExamResult::with('student.user', 'class_section')
            ->select('class_section_id', 'student_id', 'percentage', 'grade', DB::raw('MAX(percentage) as max_percentage'))
            ->groupBy('class_section_id')
            ->whereNot('grade', 'Fail')
            ->get();

        return compact('teacher', 'student', 'parent', 'teachers', 'boys', 'girls', 'rankers');
    }

    private function getTeacherClassSections(int $teacher_id): ?Collection
    {
        $class_section_ids = ClassTeacher::select('class_section_id')
            ->where('class_teacher_id', $teacher_id)
            ->pluck('class_section_id');

        if ($class_section_ids->isNotEmpty()) {
            return ClassSection::with('class', 'section', 'class.medium', 'class.streams')
                ->whereIn('id', $class_section_ids)
                ->get();
        }

        return null;
    }

    private function getUpcomingLeaves(int $session_year_id): ?Collection
    {
        $today_date = Carbon::now()->format('Y-m-d');

        return LeaveDetail::whereHas('leave', function ($query) use ($today_date, $session_year_id) {
                $query->where('status', 1)
                    ->whereHas('leave_master', fn($q) => $q->where('session_year_id', $session_year_id));
            })
            ->with('leave.user')
            ->whereDate('date', '>=', $today_date)
            ->orderBy('date', 'ASC')
            ->take(10)
            ->get();
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->flush();
        $request->session()->regenerate();
        
        return redirect('/');
    }

    public function getSubjectByClassSection(Request $request): JsonResponse
    {
        $currentSemester = Semester::get()->first(function ($semester) {
            return $semester->current;
        });

        $classSectionId = (int) $request->input('class_section_id');
        $classSection = ClassSection::select('class_id')->where('id', $classSectionId)->first();

        if (!$classSection) {
            return response()->json([]);
        }

        $class = ClassSchool::find($classSection->class_id);
        if (!$class) {
            return response()->json([]);
        }

        if ($class->include_semesters == 1 && $currentSemester) {
            $subjects = ClassSubject::SubjectTeacher($classSectionId)
                ->where('class_id', $class->id)
                ->where('semester_id', $currentSemester->id)
                ->with('subject')
                ->get();
        } else {
            $subjects = ClassSubject::SubjectTeacher($classSectionId)
                ->where('class_id', $class->id)
                ->with('subject')
                ->get();
        }

        return response()->json($subjects);
    }

    public function getTeacherByClassSubject(Request $request): JsonResponse
    {
        $teachers = Teacher::with('user')->get();
        return response()->json($teachers);
    }

    public function resetPasswordView(): View
    {
        return view('settings.reset_password');
    }

    public function editProfile(): View
    {
        $admin_data = Auth::user();  
        return view('settings.update_profile', compact('admin_data'));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'mobile' => 'required|digits_between:1,16',
            'image' => 'nullable|mimes:jpg,jpeg,png|max:2048',
            'gender' => 'required|in:male,female',
            'dob' => 'required|date',
            'current_address' => 'required',
            'permanent_address' => 'required',
        ]);

        try {
            $user = Auth::user();
            $data = $request->only([
                'first_name', 'last_name', 'mobile', 'gender', 
                'current_address', 'permanent_address'
            ]);
            
            $data['dob'] = date('Y-m-d', strtotime($request->input('dob')));

            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($user->getRawOriginal('image')) {
                    Storage::disk('public')->delete($user->getRawOriginal('image'));
                }

                $file = $request->file('image');
                $fileName = time() . '-' . $file->getClientOriginalName();
                $filePath = 'profile/' . $fileName;
                
                resizeImage($file);
                $destinationPath = storage_path('app/public/profile');
                $file->move($destinationPath, $fileName);
                
                $data['image'] = $filePath;
            }

            $user->update($data);

            return response()->json([
                'error' => false,
                'message' => trans('data_update_successfully')
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => true,
                'message' => trans('error_occurred')
            ]);
        }
    }

    public function updateWarningModal(Request $request): JsonResponse
    {
        try {
            // Implementation for warning modal update
            return response()->json([
                'error' => false,
                'message' => 'Warning modal updated successfully'
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => true,
                'message' => trans('error_occurred')
            ]);
        }
    }
}
