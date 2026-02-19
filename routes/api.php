<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PrintController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::post('/print', [PrintController::class, 'print']);
Route::get('/status', [PrintController::class, 'status']);
Route::get('/print/status', [PrintController::class, 'status']);
Route::post('/test-print', [PrintController::class, 'testPrint']);
