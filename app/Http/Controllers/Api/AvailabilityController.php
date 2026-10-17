<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\InstructorProfile;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    // Public: View availability for an approved instructor
    public function index($instructor)
    {
        $profile = InstructorProfile::where('approved', true)
            ->findOrFail($instructor->id ?? $instructor);

        return response()->json(
            $profile->availabilities()
                ->where('is_available', true)
                ->where('start_at', '>', now())
                ->orderBy('start_at')
                ->get()
        );
    }

    // Instructor: View their own availability slots
    public function myAvailability(Request $request)
    {
        abort_unless(
            $request->user()->role === 'instructor',
            403,
            'Only instructors can view their availability.'
        );

        $profile = InstructorProfile::where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(
            $profile->availabilities()
                ->orderBy('start_at')
                ->get()
        );
    }

    // Instructor: Create an availability slot
    public function store(Request $request)
    {
        abort_unless(
            $request->user()->role === 'instructor',
            403,
            'Only instructors can create availability.'
        );

        $profile = InstructorProfile::where('user_id', $request->user()->id)
            ->firstOrFail();

        abort_unless($profile->approved, 403, 'Your profile must be approved first.');

        $validated = $request->validate([
            'start_at' => ['required', 'date', 'after:now'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ]);

        $overlap = Availability::where(
            'instructor_profile_id',
            $profile->id
        )
            ->where('start_at', '<', $validated['end_at'])
            ->where('end_at', '>', $validated['start_at'])
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'This time overlaps an existing availability slot.',
            ], 422);
        }

        $bookingConflict = Booking::where('instructor_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('start_at', '<', $validated['end_at'])
            ->where('end_at', '>', $validated['start_at'])
            ->exists();

        if ($bookingConflict) {
            return response()->json([
                'message' => 'This time overlaps an existing booking.',
            ], 422);
        }

        $availability = $profile->availabilities()->create([
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'is_available' => true,
        ]);

        return response()->json([
            'message' => 'Availability created successfully.',
            'availability' => $availability,
        ], 201);
    }

    // Instructor: Update their own availability slot
    public function update(Request $request, Availability $availability)
    {
        abort_unless(
            $request->user()->role === 'instructor',
            403
        );

        $profile = InstructorProfile::where('user_id', $request->user()->id)
            ->firstOrFail();

        abort_unless(
            $availability->instructor_profile_id === $profile->id,
            403,
            'You cannot update another instructor’s availability.'
        );

        abort_unless($profile->approved, 403, 'Your profile must be approved first.');

        $validated = $request->validate([
            'start_at' => ['required', 'date', 'after:now'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ]);

        $overlap = Availability::where('instructor_profile_id', $profile->id)
            ->where('id', '!=', $availability->id)
            ->where('start_at', '<', $validated['end_at'])
            ->where('end_at', '>', $validated['start_at'])
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'This time overlaps an existing availability slot.',
            ], 422);
        }

        $existingSlotHasBooking = Booking::where('instructor_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('start_at', '<', $availability->end_at)
            ->where('end_at', '>', $availability->start_at)
            ->exists();

        if ($existingSlotHasBooking) {
            return response()->json([
                'message' => 'Cannot update availability slot because it has an existing booking.',
            ], 422);
        }

        $bookingConflict = Booking::where('instructor_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('start_at', '<', $validated['end_at'])
            ->where('end_at', '>', $validated['start_at'])
            ->exists();

        if ($bookingConflict) {
            return response()->json([
                'message' => 'This time overlaps an existing booking.',
            ], 422);
        }

        $availability->update([
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
        ]);

        return response()->json([
            'message' => 'Availability updated successfully.',
            'availability' => $availability,
        ]);
    }

    // Instructor: Delete their own availability slot
    public function destroy(Request $request, Availability $availability)
    {
        abort_unless(
            $request->user()->role === 'instructor',
            403
        );

        $profile = InstructorProfile::where('user_id', $request->user()->id)
            ->firstOrFail();

        abort_unless(
            $availability->instructor_profile_id === $profile->id,
            403,
            'You cannot delete another instructor’s availability.'
        );

        $hasActiveBooking = Booking::where('instructor_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('start_at', '<', $availability->end_at)
            ->where('end_at', '>', $availability->start_at)
            ->exists();

        if ($hasActiveBooking) {
            return response()->json([
                'message' => 'Cannot delete availability slot with an existing booking.',
            ], 422);
        }

        $availability->delete();

        return response()->json([
            'message' => 'Availability deleted successfully.',
        ]);
    }
}
