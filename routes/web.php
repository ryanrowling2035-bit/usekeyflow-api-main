<?php

use App\Http\Controllers\Api\RequestLogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/redirect', function (Request $request) {
    $enquiryId = $request->query('enquiryId');

    return view('deeplink-redirect', ['enquiryId' => $enquiryId]);
});

Route::middleware('auth.logs')->group(function () {
    Route::get('/request-logs', [RequestLogController::class, 'index']);
    Route::get('/request-logs/{date}', [RequestLogController::class, 'show']);
    Route::get('/request-logs/{date}/download', [RequestLogController::class, 'download']);
    Route::post('/request-logs/{date}/delete', [RequestLogController::class, 'delete']);
    Route::post('/request-logs/{date}/reset', [RequestLogController::class, 'reset']);
});
