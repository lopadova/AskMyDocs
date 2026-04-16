<?php

use App\Http\Controllers\Api\KbChatController;
use App\Http\Controllers\Api\KbIngestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/kb/chat', KbChatController::class);
    Route::post('/kb/ingest', KbIngestController::class);
});
