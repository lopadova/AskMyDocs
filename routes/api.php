<?php

use App\Http\Controllers\Api\KbChatController;
use App\Http\Controllers\Api\KbDeleteController;
use App\Http\Controllers\Api\KbIngestController;
use App\Http\Controllers\Api\KbPromotionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/kb/chat', KbChatController::class);
    Route::post('/kb/ingest', KbIngestController::class);
    Route::delete('/kb/documents', KbDeleteController::class);

    // Promotion pipeline (Phase 4). suggest + candidates write nothing;
    // only `promote` writes canonical markdown to the KB disk.
    Route::post('/kb/promotion/suggest', [KbPromotionController::class, 'suggest']);
    Route::post('/kb/promotion/candidates', [KbPromotionController::class, 'candidates']);
    Route::post('/kb/promotion/promote', [KbPromotionController::class, 'promote']);
});
