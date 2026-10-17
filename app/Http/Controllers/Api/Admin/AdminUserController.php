<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $request->validate([
            'role' => ['sometimes', 'nullable', 'string', 'in:student,instructor,admin'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $query = User::with(['studentProfile', 'instructorProfile']);

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate(15)
        );
    }

    public function show(Request $request, User $user)
    {
        abort_unless($request->user()->role === 'admin', 403);

        return response()->json(
            $user->load(['studentProfile', 'instructorProfile'])
                ->loadCount(['studentBookings', 'instructorBookings'])
        );
    }
}
