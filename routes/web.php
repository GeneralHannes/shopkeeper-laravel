<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

// The self-contained SPA (reused unchanged from the original). Static assets
// (vendor/, rootCA.pem) live in public/static and are served directly.
Route::get('/', [ApiController::class, 'index']);
