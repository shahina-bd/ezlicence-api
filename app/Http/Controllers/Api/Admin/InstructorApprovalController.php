<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstructorProfile;
use Illuminate\Http\Request;

class InstructorApprovalController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);

        return response()->json(
            InstructorProfile::with('user')
                ->where('approved', false)
                ->paginate(15)
        );
    }

    public function update(Request $request, InstructorProfile $instructor)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
        ]);

        $instructor->approved = $validated['approved'];
        $instructor->save();

        return response()->json([
            'message' => $validated['approved']
                ? 'Instructor approved successfully.'
                : 'Instructor approval revoked.',
            'instructor' => $instructor->fresh()->load('user'),
        ]);
    }
}
