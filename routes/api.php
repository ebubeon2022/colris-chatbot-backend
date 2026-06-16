<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookRequestController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\AdminController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/settings/public', [AdminController::class, 'getPublicSettings']);
Route::get('/books/public', [AdminController::class, 'getPublicBooks']);
Route::get('/books/new-arrivals', [AdminController::class, 'getNewArrivals']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');
Route::post('/chat/feedback', [ChatController::class, 'feedback'])->middleware('auth:sanctum');
Route::get('/admin/feedback', [ChatController::class, 'adminFeedback'])->middleware('auth:sanctum');

// Book requests
Route::post('/book-requests', [BookRequestController::class, 'store'])->middleware('auth:sanctum');
Route::get('/book-requests/my', [BookRequestController::class, 'myRequests'])->middleware('auth:sanctum');
Route::get('/admin/book-requests', [BookRequestController::class, 'index'])->middleware('auth:sanctum');
Route::put('/admin/book-requests/{id}', [BookRequestController::class, 'update'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/chat', [ChatController::class, 'respond']);
    Route::get('/chat/history', [ChatController::class, 'history']);
    Route::get('/chat/session/{sessionId}', [ChatController::class, 'getSession']);
    Route::get('/books/search', [BookController::class, 'search']);

    // Admin settings
    Route::get('/admin/settings', [AdminController::class, 'getSettings']);
    Route::put('/admin/settings/{key}', [AdminController::class, 'updateSetting']);

    // Admin users
    Route::get('/admin/users', [AdminController::class, 'getUsers']);
    Route::get('/admin/logs', [AdminController::class, 'getConversationLogs']);
    Route::delete('/admin/logs/old', [AdminController::class, 'deleteOldLogs']);
    Route::get('/admin/dashboard', [AdminController::class, 'getDashboard']);
    Route::put('/admin/users/{id}/role', [AdminController::class, 'updateUserRole']);
    Route::delete('/admin/users/{id}', [AdminController::class, 'deleteUser']);

    // Admin books — import MUST come before {id} routes
    Route::get('/admin/books', [AdminController::class, 'getBooks']);
    Route::post('/admin/books/import', [AdminController::class, 'importBooks']);
    Route::post('/admin/books', [AdminController::class, 'addBook']);
    Route::put('/admin/books/{id}', [AdminController::class, 'updateBook']);
    Route::delete('/admin/books/{id}', [AdminController::class, 'deleteBook']);

    // Knowledge base
    Route::get('/admin/knowledge', [AdminController::class, 'getKnowledge']);
    Route::post('/admin/knowledge', [AdminController::class, 'addKnowledge']);
    Route::put('/admin/knowledge/{id}', [AdminController::class, 'updateKnowledge']);
    Route::delete('/admin/knowledge/{id}', [AdminController::class, 'deleteKnowledge']);
    Route::put('/admin/knowledge/{id}/toggle', [AdminController::class, 'toggleKnowledge']);

    // AI Personality
    Route::get('/admin/personality', [AdminController::class, 'getPersonality']);
    Route::put('/admin/personality', [AdminController::class, 'updatePersonality']);
});
Route::options('{any}', function() { return response('', 200); })->where('any', '.*');
