<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstructorProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstructorController extends Controller
{
    // Public: list approved instructors and search by postcode
    public function index(Request $request)
    {
        $request->validate([
            'postcode' => ['sometimes', 'nullable', 'string', 'max:20'],
            'transmission' => [
                'sometimes',
                'nullable',
                Rule::in(['manual', 'automatic']),
            ],
        ]);

        return response()->json(
            InstructorProfile::with('user')
                ->where('approved', true)
                ->when($request->filled('postcode'), function ($query) use ($request) {
                    $postcode = strtoupper(trim($request->postcode));

                    $query->where('postcode', 'like', '%' . $postcode . '%');
                })
                ->when($request->filled('transmission'), function ($query) use ($request) {
                    $query->where('transmission', $request->transmission);
                })
                ->paginate(12)
        );
    }

    // Public: show one approved instructor
    public function show(InstructorProfile $instructor)
    {
        abort_unless($instructor->approved, 404);

        return response()->json(
            $instructor->load('user')
        );
    }

    // Authenticated instructor: create profile
    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'instructor', 403);

        if ($request->user()->instructorProfile()->exists()) {
            return response()->json([
                'message' => 'Instructor profile already exists.',
            ], 409);
        }

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'bio' => ['nullable', 'string', 'max:3000'],
            'profile_image' => ['nullable', 'string', 'max:2048'],
            'postcode' => ['required', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'transmission' => [
                'required',
                Rule::in(['manual', 'automatic']),
            ],
            'hourly_rate' => ['required', 'numeric', 'min:1', 'max:500'],
        ]);

        $validated['postcode'] = strtoupper(trim($validated['postcode']));

        $profile = new InstructorProfile($validated);
        $profile->user_id = $request->user()->id;
        $profile->approved = false;
        $profile->save();

        return response()->json([
            'message' => 'Instructor profile created and awaiting approval.',
            'instructor' => $profile,
        ], 201);
    }

    // Owner or admin: update profile
    public function update(Request $request, InstructorProfile $instructor)
    {
        abort_unless(
            $request->user()->role === 'admin'
            || (
                $request->user()->role === 'instructor'
                && $instructor->user_id === $request->user()->id
            ),
            403
        );

        $validated = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'profile_image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'postcode' => ['sometimes', 'string', 'max:10'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'transmission' => [
                'sometimes',
                Rule::in(['manual', 'automatic']),
            ],
            'hourly_rate' => ['sometimes', 'numeric', 'min:1', 'max:500'],
        ]);

        if (isset($validated['postcode'])) {
            $validated['postcode'] = strtoupper(trim($validated['postcode']));
        }

        // Instructors cannot approve themselves.
        $instructor->update($validated);

        return response()->json([
            'message' => 'Instructor profile updated.',
            'instructor' => $instructor->fresh(),
        ]);
    }

    // Owner or admin: delete profile
    public function destroy(Request $request, InstructorProfile $instructor)
    {
        abort_unless(
            $request->user()->role === 'admin'
            || (
                $request->user()->role === 'instructor'
                && $instructor->user_id === $request->user()->id
            ),
            403
        );

        $instructor->delete();

        return response()->json([
            'message' => 'Instructor profile deleted.',
        ]);
    }
}
