<?php

use App\Http\Controllers\{AnalyticsController, AuthController, CrudController};
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login']);

Route::middleware('token')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('analytics', AnalyticsController::class);

    $res = 'customers|products|sales|campaigns|suppliers';
    Route::get('{res}', [CrudController::class, 'index'])->where('res', $res);
    Route::post('{res}', [CrudController::class, 'store'])->where('res', $res);
    Route::put('{res}/{id}', [CrudController::class, 'update'])->where('res', $res);
    Route::delete('{res}/{id}', [CrudController::class, 'destroy'])->where('res', $res);
});
