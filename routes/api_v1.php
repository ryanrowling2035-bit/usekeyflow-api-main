<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\ListingController;
use App\Http\Controllers\Api\V1\PipelineController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Keyflow Public API (Version 1)
|--------------------------------------------------------------------------
| Reusable REST API framework for Keyflow supporting Exclusive Links
| Marketing OS and future Keyflow clients.
|
| - Authentication: Bearer Token (Sanctum) or X-API-KEY header
| - Rate Limits: 120 requests/minute per client
| - Format: JSON, snake_case, ISO 8601 UTC dates
*/

Route::prefix('v1')
    ->middleware(['public.api.auth', 'throttle:keyflow-public-api'])
    ->group(function () {
        // Leads
        Route::get('leads', [LeadController::class, 'index']);
        Route::post('leads', [LeadController::class, 'store']);
        Route::get('leads/{id}', [LeadController::class, 'show']);
        Route::match(['post', 'put', 'patch'], 'leads/{id}', [LeadController::class, 'update']);

        // Deals & Commissions
        Route::get('deals', [DealController::class, 'index']);

        // Live Pipeline Summary
        Route::get('pipeline', [PipelineController::class, 'index']);

        // Agents Directory
        Route::get('agents', [AgentController::class, 'index']);

        // Listings
        Route::get('listings', [ListingController::class, 'index']);
    });
