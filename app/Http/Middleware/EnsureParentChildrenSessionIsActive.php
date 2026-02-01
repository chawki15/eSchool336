<?php

namespace App\Http\Middleware;

use App\Models\Students;
use App\Models\StudentSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureParentChildrenSessionIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $session_year_id = getSettings('session_year')['session_year'];
        $children = Students::where(function ($q) use ($user) {
            $q->where('father_id', $user->parent->id)
                ->orWhere('mother_id', $user->parent->id)
                ->orWhere('guardian_id', $user->parent->id);
        })
            ->with('user')
            ->get();

        $hasActiveChild = false;

        foreach ($children as $child) {

            // Check session only for non–new admissions
            $session = StudentSessions::where('session_year_id', $session_year_id)
                ->where('student_id', $child->id)
                ->first();

            if (! $session) {
                return response()->json([
                    'message' => 'Your account is not active for the current academic year because promotion to the next class has not been completed. Please contact the administration.',
                    'code'    => 'SESSION_NOT_ACTIVE',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Skip deactivated students
            if ((int) $session->status !== 1) {
                continue;
            }

            $hasActiveChild = true;
        }

        // All children are deactivated
        if (! $hasActiveChild) {
            return response()->json([
                'message' => 'Your account is deactivated. Please contact admin for further help.',
                'code'    => 'ACCOUNT_DEACTIVATED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
