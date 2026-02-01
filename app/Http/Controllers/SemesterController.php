<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Semester;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SemesterController extends Controller
{
    /**
     * Display a listing of semesters.
     */
    public function index()
    {
        if (!Auth::user()->can('semester-list')) {
            return redirect(route('home'))
                ->withErrors(['message' => trans('no_permission_message')]);
        }

        return response(view('semester.index'));
    }

    /**
     * Store a newly created semester.
     */
    public function store(Request $request)
    {
        if (!Auth::user()->can('semester-create')) {
            return response()->json([
                'error' => true,
                'message' => trans('no_permission_message')
            ]);
        }

        // Validation: end_date must come after start_date
        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:191',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ]);
        }

        try {
            $startDate = Carbon::parse($request->start_date);
            $endDate   = Carbon::parse($request->end_date);

            // Check for overlapping date ranges
            $checkOverlap = $this->checkIfDateRangeOverlaps($startDate, $endDate);

            if ($checkOverlap['error']) {
                return response()->json($checkOverlap);
            }

            Semester::create([
                'name'       => $request->name,
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]);

            return response()->json([
                'error' => false,
                'message' => trans('data_store_successfully'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Show a paginated list (used by datatables / AJAX).
     */
    public function show()
    {
        if (!Auth::user()->can('semester-list')) {
            return response()->json([
                'error' => true,
                'message' => trans('no_permission_message')
            ]);
        }

        $offset = request('offset', 0);
        $limit  = request('limit', 10);
        $sort   = request('sort', 'id');
        $order  = request('order', 'ASC');
        $search = request('search');

        $query = Semester::query();

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'LIKE', "%$search%")
                    ->orWhere('name', 'LIKE', "%$search%");
            });
        }

        $total = $query->count();
        $res   = $query->orderBy($sort, $order)
            ->skip($offset)
            ->take($limit)
            ->get();

        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '<a href=' . route('semester.edit', $row->id) . ' class="btn btn-xs btn-gradient-primary btn-rounded btn-icon edit-data" data-id=' . $row->id . ' title="Edit" data-toggle="modal" data-target="#editModal"><i class="fa fa-edit"></i></a>&nbsp;&nbsp;';
            $operate .= '<a href=' . route('semester.destroy', $row->id) . ' class="btn btn-xs btn-gradient-danger btn-rounded btn-icon delete-form" data-id=' . $row->id . '><i class="fa fa-trash"></i></a>';

            $rows[] = [
                'id'          => $row->id,
                'no'          => $no++,
                'name'        => $row->name,
                'start_date'  => $row->start_date->format('Y-m-d'),
                'end_date'    => $row->end_date->format('Y-m-d'),
                'status'      => $row->current ? 1 : 0,
                'operate'     => $operate,
                'created_at'  => convertDateFormat($row->created_at, 'd-m-Y H:i:s'),
                'updated_at'  => convertDateFormat($row->updated_at, 'd-m-Y H:i:s'),
            ];
        }

        return response()->json([
            'total' => $total,
            'rows'  => $rows,
        ]);
    }

    /**
     * Update an existing semester.
     */
    public function update(Request $request)
    {
        if (!Auth::user()->can('semester-edit')) {
            return response()->json([
                'error' => true,
                'message' => trans('no_permission_message')
            ]);
        }

        $validator = Validator::make($request->all(), [
            'edit_id'         => 'required|exists:semesters,id',
            'edit_name'       => 'required|string|max:191',
            'edit_start_date' => 'required|date',
            'edit_end_date'   => 'required|date|after:edit_start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ]);
        }

        try {
            $semester = Semester::findOrFail($request->edit_id);

            $startDate = Carbon::parse($request->edit_start_date);
            $endDate   = Carbon::parse($request->edit_end_date);

            $checkOverlap = $this->checkIfDateRangeOverlaps($startDate, $endDate, $semester->id);

            if ($checkOverlap['error']) {
                return response()->json($checkOverlap);
            }

            $semester->update([
                'name'       => $request->edit_name,
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]);

            return response()->json([
                'error' => false,
                'message' => trans('data_update_successfully'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove a semester.
     */
    public function destroy($id)
    {
        if (!Auth::user()->can('semester-delete')) {
            return response()->json([
                'error' => true,
                'message' => trans('no_permission_message')
            ]);
        }

        try {
            $semester = Semester::findOrFail($id);
            $semester->delete();

            return response()->json([
                'error' => false,
                'message' => trans('data_delete_successfully'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => true,
                'message' => trans('error_occurred'),
                'data' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a new semester overlaps with an existing one (date-based logic).
     */
    private function checkIfDateRangeOverlaps(Carbon $start, Carbon $end, ?int $ignoreID = null): array
    {
        $query = Semester::query();
        if ($ignoreID) {
            $query->where('id', '!=', $ignoreID);
        }

        $semesters = $query->get();

        foreach ($semesters as $semester) {
            $existingStart = Carbon::parse($semester->start_date);
            $existingEnd   = Carbon::parse($semester->end_date);

            // Overlap condition: start < existing_end && end > existing_start
            if ($start->lt($existingEnd) && $end->gt($existingStart)) {
                return [
                    'error' => true,
                    'message' => trans('semester_overlap_message'),
                    'data' => [
                        'conflict_with' => $semester->name,
                        'existing_start' => $existingStart->toDateString(),
                        'existing_end' => $existingEnd->toDateString(),
                    ],
                ];
            }
        }

        return ['error' => false, 'message' => 'success'];
    }
}
