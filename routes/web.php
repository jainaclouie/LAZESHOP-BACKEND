<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => 'Laze Vapeshop API',
    'docs' => 'All endpoints live under /api. The React app is in the /frontend folder.',
]));
