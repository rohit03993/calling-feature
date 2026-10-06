<?php

use App\Http\Controllers\CallController;
use App\Http\Controllers\TestCallController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1', 'school.token'])->group(function () {
    Route::post('calls', [CallController::class, 'store']);
    Route::post('calls/{callId}/audio', [CallController::class, 'upload']);
    Route::get('calls/{callId}', [CallController::class, 'show']);
    Route::get('calls/{callId}/audio', [CallController::class, 'audio']);
    Route::post('calls/{callId}/retry', [CallController::class, 'retry']);

    Route::post('test/transcribe', [TestCallController::class, 'transcribe']);
    Route::post('test/analyze', [TestCallController::class, 'analyze']);
});
