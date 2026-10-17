<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\InstructorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function store(Request $request)
    {
        abort_unless(
            $request->user()->role === 'student',
            403,
            'Only students can create bookings.'
        );

        $validated = $request->validate([
            'instructor_profile_id' => ['required', 'integer', 'exists:instructor_profiles,id'],
            'start_at' => ['required', 'date', 'after:now'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'pickup_postcode' => ['required', 'string', 'max:20'],
        ]);

        $booking = DB::transaction(function () use ($request, $validated) {
            $instructor = InstructorProfile::whereKey(
                $validated['instructor_profile_id']
            )
                ->where('approved', true)
                ->lockForUpdate()
                ->firstOrFail();

            $slot = Availability::where(
                'instructor_profile_id',
                $instructor->id
            )
                ->where('is_available', true)
                ->where('start_at', '<=', $validated['start_at'])
                ->where('end_at', '>=', $validated['end_at'])
                ->lockForUpdate()
                ->first();

            if (!$slot) {
                throw ValidationException::withMessages([
                    'start_at' => 'The instructor is not available for this time.',
                ]);
            }

            $overlap = Booking::where(
                'instructor_profile_id',
                $instructor->id
            )
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('start_at', '<', $validated['end_at'])
                ->where('end_at', '>', $validated['start_at'])
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'start_at' => 'This time has already been booked.',
                ]);
            }

            $minutes = (
                strtotime($validated['end_at']) -
                strtotime($validated['start_at'])
            ) / 60;

            if ($minutes <= 0 || $minutes % 60 !== 0) {
                throw ValidationException::withMessages([
                    'end_at' => 'Lessons must be a positive whole number of hours.',
                ]);
            }

            $totalPrice = (float) $instructor->hourly_rate * ($minutes / 60);

            $booking = Booking::create([
                'student_id' => $request->user()->id,
                'instructor_profile_id' => $instructor->id,
                'start_at' => $validated['start_at'],
                'end_at' => $validated['end_at'],
                'pickup_postcode' => strtoupper($validated['pickup_postcode']),
                'total_price' => number_format($totalPrice, 2, '.', ''),
                'status' => 'pending',
                'payment_status' => 'unpaid',
            ]);

            return $booking;
        });

        return response()->json([
            'message' => 'Booking request created successfully.',
            'booking' => $booking->load('instructorProfile.user'),
        ], 201);
    }

    public function myBookings(Request $request)
    {
        $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,confirmed,completed,cancelled,rejected'],
        ]);

        $query = Booking::with('instructorProfile.user')
            ->where('student_id', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(
            $query->latest()->paginate(10)
        );
    }

    public function instructorBookings(Request $request)
    {
        abort_unless(
            $request->user()->role === 'instructor',
            403,
            'Only instructors can view instructor bookings.'
        );

        $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,confirmed,completed,cancelled,rejected'],
        ]);

        $profile = InstructorProfile::where('user_id', $request->user()->id)->firstOrFail();

        $query = Booking::with(['student', 'instructorProfile.user'])
            ->where('instructor_profile_id', $profile->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(
            $query->latest()->paginate(10)
        );
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'student') {
            return $this->myBookings($request);
        }

        if ($user->role === 'instructor') {
            return $this->instructorBookings($request);
        }

        if ($user->role === 'admin') {
            $request->validate([
                'status' => ['sometimes', 'nullable', 'string', 'in:pending,confirmed,completed,cancelled,rejected'],
            ]);

            $query = Booking::with(['student', 'instructorProfile.user']);

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            return response()->json(
                $query->latest()->paginate(10)
            );
        }

        abort(403);
    }

    public function show(Request $request, $id)
    {
        $booking = Booking::with(['student', 'instructorProfile.user'])->findOrFail($id);

        $user = $request->user();
        $isStudent = $booking->student_id === $user->id;
        $isInstructor = $booking->instructorProfile && $booking->instructorProfile->user_id === $user->id;
        $isAdmin = $user->role === 'admin';

        abort_unless($isStudent || $isInstructor || $isAdmin, 403, 'Unauthorized to view this booking.');

        return response()->json([
            'booking' => $booking,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:confirmed,rejected,completed,cancelled'],
        ]);

        return match ($validated['status']) {
            'confirmed' => $this->confirm($request, $id),
            'rejected' => $this->reject($request, $id),
            'completed' => $this->complete($request, $id),
            'cancelled' => $this->cancel($request, $id),
        };
    }

    public function confirm(Request $request, $id)
    {
        $booking = Booking::with(['student', 'instructorProfile.user'])->findOrFail($id);

        $user = $request->user();
        $isInstructor = $booking->instructorProfile && $booking->instructorProfile->user_id === $user->id;
        $isAdmin = $user->role === 'admin';

        abort_unless($isInstructor || $isAdmin, 403, 'Unauthorized to confirm this booking.');

        if ($isInstructor && !$booking->instructorProfile->approved) {
            abort(403, 'Your instructor profile must be approved to confirm bookings.');
        }

        if ($booking->status === 'confirmed') {
            return response()->json([
                'message' => 'This booking has already been confirmed.',
            ], 422);
        }

        if ($booking->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending bookings can be confirmed.',
            ], 422);
        }

        if ($booking->start_at->isPast()) {
            return response()->json([
                'message' => 'Past bookings cannot be confirmed.',
            ], 422);
        }

        DB::transaction(function () use ($booking) {
            $conflict = Booking::where('instructor_profile_id', $booking->instructor_profile_id)
                ->where('id', '!=', $booking->id)
                ->where('status', 'confirmed')
                ->where('start_at', '<', $booking->end_at)
                ->where('end_at', '>', $booking->start_at)
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'start_at' => 'Another booking is already confirmed for this time slot.',
                ]);
            }

            $booking->update([
                'status' => 'confirmed',
            ]);

            Availability::where('instructor_profile_id', $booking->instructor_profile_id)
                ->where('start_at', $booking->start_at)
                ->where('end_at', $booking->end_at)
                ->update(['is_available' => false]);
        });

        return response()->json([
            'message' => 'Booking confirmed successfully.',
            'booking' => $booking->fresh()->load(['student', 'instructorProfile.user']),
        ]);
    }

    public function reject(Request $request, $id)
    {
        $booking = Booking::with(['student', 'instructorProfile.user'])->findOrFail($id);

        $user = $request->user();
        $isInstructor = $booking->instructorProfile && $booking->instructorProfile->user_id === $user->id;
        $isAdmin = $user->role === 'admin';

        abort_unless($isInstructor || $isAdmin, 403, 'Unauthorized to reject this booking.');

        if ($isInstructor && !$booking->instructorProfile->approved) {
            abort(403, 'Your instructor profile must be approved to reject bookings.');
        }

        if ($booking->status === 'rejected') {
            return response()->json([
                'message' => 'This booking has already been rejected.',
            ], 422);
        }

        if ($booking->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending bookings can be rejected.',
            ], 422);
        }

        DB::transaction(function () use ($booking) {
            $booking->update([
                'status' => 'rejected',
            ]);

            Availability::where('instructor_profile_id', $booking->instructor_profile_id)
                ->where('start_at', '<=', $booking->start_at)
                ->where('end_at', '>=', $booking->end_at)
                ->update(['is_available' => true]);
        });

        return response()->json([
            'message' => 'Booking rejected successfully.',
            'booking' => $booking->fresh()->load(['student', 'instructorProfile.user']),
        ]);
    }

    public function complete(Request $request, $id)
    {
        $booking = Booking::with(['student', 'instructorProfile.user'])->findOrFail($id);

        $user = $request->user();
        $isInstructor = $booking->instructorProfile && $booking->instructorProfile->user_id === $user->id;
        $isAdmin = $user->role === 'admin';

        abort_unless($isInstructor || $isAdmin, 403, 'Unauthorized to complete this booking.');

        if ($booking->status === 'completed') {
            return response()->json([
                'message' => 'This booking has already been completed.',
            ], 422);
        }

        if ($booking->status !== 'confirmed') {
            return response()->json([
                'message' => 'Only confirmed bookings can be marked as completed.',
            ], 422);
        }

        $booking->update([
            'status' => 'completed',
        ]);

        return response()->json([
            'message' => 'Booking marked as completed.',
            'booking' => $booking->fresh()->load(['student', 'instructorProfile.user']),
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $booking = Booking::with('instructorProfile')->findOrFail($id);

        $user = $request->user();
        $isStudent = $booking->student_id === $user->id;
        $isInstructor = $booking->instructorProfile && $booking->instructorProfile->user_id === $user->id;
        $isAdmin = $user->role === 'admin';

        abort_unless($isStudent || $isInstructor || $isAdmin, 403, 'Unauthorized to cancel this booking.');

        if ($booking->status === 'cancelled') {
            return response()->json([
                'message' => 'This booking has already been cancelled.',
            ], 422);
        }

        if ($booking->status === 'completed') {
            return response()->json([
                'message' => 'Completed bookings cannot be cancelled.',
            ], 422);
        }

        if ($booking->status === 'rejected') {
            return response()->json([
                'message' => 'Rejected bookings cannot be cancelled.',
            ], 422);
        }

        // Cancellation policy: students cannot cancel past lessons or with less than 24h notice
        if ($isStudent && !$isAdmin) {
            if ($booking->start_at->isPast()) {
                return response()->json([
                    'message' => 'Past bookings cannot be cancelled.',
                ], 422);
            }

            if (now()->gte($booking->start_at->copy()->subHours(24))) {
                return response()->json([
                    'message' => 'Bookings cannot be cancelled less than 24 hours before the lesson start time.',
                ], 422);
            }
        }

        DB::transaction(function () use ($booking) {
            $booking->update([
                'status' => 'cancelled',
            ]);

            Availability::where('instructor_profile_id', $booking->instructor_profile_id)
                ->where('start_at', '<=', $booking->start_at)
                ->where('end_at', '>=', $booking->end_at)
                ->update(['is_available' => true]);
        });

        return response()->json([
            'message' => 'Booking cancelled successfully.',
            'booking' => $booking->fresh()->load(['student', 'instructorProfile.user']),
        ]);
    }
}
