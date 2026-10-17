<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\InstructorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminBookingController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['pending', 'confirmed', 'completed', 'cancelled', 'rejected'])],
            'payment_status' => ['sometimes', 'nullable', Rule::in(['unpaid', 'paid', 'refunded'])],
            'instructor_profile_id' => ['sometimes', 'nullable', 'integer'],
            'student_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $query = Booking::with(['student', 'instructorProfile.user']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('instructor_profile_id')) {
            $query->where('instructor_profile_id', $request->instructor_profile_id);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->filled('from')) {
            $query->where('start_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('end_at', '<=', $request->to);
        }

        return response()->json(
            $query->orderBy('start_at', 'desc')->paginate(15)
        );
    }

    public function show(Request $request, Booking $booking)
    {
        abort_unless($request->user()->role === 'admin', 403);

        return response()->json(
            $booking->load(['student', 'instructorProfile.user'])
        );
    }

    public function updateStatus(Request $request, Booking $booking)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'confirmed', 'completed', 'cancelled', 'rejected'])],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'paid', 'refunded'])],
        ]);

        DB::transaction(function () use ($booking, $validated) {
            $updates = ['status' => $validated['status']];
            if (isset($validated['payment_status'])) {
                $updates['payment_status'] = $validated['payment_status'];
            }

            $booking->update($updates);

            // If cancelled or rejected by admin, release matching availability slot
            if (in_array($validated['status'], ['cancelled', 'rejected'], true)) {
                Availability::where('instructor_profile_id', $booking->instructor_profile_id)
                    ->where('start_at', '<=', $booking->start_at)
                    ->where('end_at', '>=', $booking->end_at)
                    ->update(['is_available' => true]);
            }
        });

        return response()->json([
            'message' => 'Booking status updated successfully.',
            'booking' => $booking->fresh()->load(['student', 'instructorProfile.user']),
        ]);
    }
}
