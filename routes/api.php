<?php

use Illuminate\Support\Facades\Route;
use Lithium\VideoLogs\Http\Controllers\VideoLogController;

// User-facing routes: full auth.
Route::middleware(config('video-logs.middleware', ['api', 'auth']))->group(function () {
    Route::post('video-logs', [VideoLogController::class, 'store']);
    Route::post('video-logs/{videoLog}/finalize_upload', [VideoLogController::class, 'finalizeUpload']);
    Route::post('video-logs/{videoLog}/upload_parts', [VideoLogController::class, 'signUploadParts']);
    Route::get('video-logs/{videoLog}/upload_status', [VideoLogController::class, 'uploadStatus']);
    Route::post('video-logs/{videoLog}/abort_upload', [VideoLogController::class, 'abortUpload']);
    Route::post('video-logs/{videoLog}/retry_processing', [VideoLogController::class, 'retryProcessing']);
    Route::post('video-logs/{videoLog}/check_status', [VideoLogController::class, 'checkStatus']);
    Route::get('video-logs/{videoLog}', [VideoLogController::class, 'show']);
    Route::get('video-logs', [VideoLogController::class, 'index']);
    Route::match(['put', 'patch'], 'video-logs/{videoLog}', [VideoLogController::class, 'update']);
    Route::delete('video-logs/{videoLog}', [VideoLogController::class, 'destroy']);
});

// Webhook: NO user auth, CSRF-exempt, verified by SNS signature instead.
// Uses only the "api" group so unauthenticated SNS deliveries are not redirected
// to login. Do NOT add an auth middleware here.
Route::middleware('api')->group(function () {
    Route::post('video-logs/webhook', [VideoLogController::class, 'handleWebhook'])
        ->name('video-logs.webhook');
});
