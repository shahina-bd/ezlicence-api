<?php

use App\Http\Controllers\Api\Admin\AdminBookingController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\InstructorApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InstructorController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Models\InstructorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->name('login');
});

Route::middleware('throttle:api')->group(function () {
    Route::get('/instructors', [InstructorController::class, 'index']);
    Route::get('/instructors/{instructor}', [InstructorController::class, 'show']);
    Route::get(
        '/instructors/{instructor}/availability',
        [AvailabilityController::class, 'index']
    );

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/instructors', [InstructorController::class, 'store']);
        Route::put('/instructors/{instructor}', [InstructorController::class, 'update']);
        Route::patch('/instructors/{instructor}', [InstructorController::class, 'update']);
        Route::delete('/instructors/{instructor}', [InstructorController::class, 'destroy']);

        Route::get(
            '/instructor/availability',
            [AvailabilityController::class, 'myAvailability']
        );

        Route::post(
            '/instructor/availability',
            [AvailabilityController::class, 'store']
        );

        Route::put(
            '/instructor/availability/{availability}',
            [AvailabilityController::class, 'update']
        );

        Route::patch(
            '/instructor/availability/{availability}',
            [AvailabilityController::class, 'update']
        );

        Route::delete(
            '/instructor/availability/{availability}',
            [AvailabilityController::class, 'destroy']
        );

        // Booking Endpoints
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/my', [BookingController::class, 'myBookings']);
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/bookings/{id}', [BookingController::class, 'show']);
        Route::patch('/bookings/{id}/status', [BookingController::class, 'updateStatus']);
        Route::patch('/bookings/{id}/cancel', [BookingController::class, 'cancel']);
        Route::patch('/bookings/{id}/confirm', [BookingController::class, 'confirm']);
        Route::patch('/bookings/{id}/reject', [BookingController::class, 'reject']);
        Route::patch('/bookings/{id}/complete', [BookingController::class, 'complete']);

        // Instructor Booking Management Endpoints
        Route::get('/instructor/bookings', [BookingController::class, 'instructorBookings']);
        Route::patch('/instructor/bookings/{id}/status', [BookingController::class, 'updateStatus']);
        Route::patch('/instructor/bookings/{id}/confirm', [BookingController::class, 'confirm']);
        Route::patch('/instructor/bookings/{id}/reject', [BookingController::class, 'reject']);
        Route::patch('/instructor/bookings/{id}/cancel', [BookingController::class, 'cancel']);
        Route::patch('/instructor/bookings/{id}/complete', [BookingController::class, 'complete']);
    });

    Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
        Route::get(
            '/instructors/pending',
            [InstructorApprovalController::class, 'index']
        );

        Route::patch(
            '/instructors/{instructor}/approval',
            [InstructorApprovalController::class, 'update']
        );

        // User management
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);

        // Booking management
        Route::get('/bookings', [AdminBookingController::class, 'index']);
        Route::get('/bookings/{booking}', [AdminBookingController::class, 'show']);
        Route::patch('/bookings/{booking}/status', [AdminBookingController::class, 'updateStatus']);
    });
});

