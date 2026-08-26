<?php

use App\Http\Controllers\CircuitController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/realtime/auth', [RealtimeController::class, 'authenticate'])->middleware('web');

Route::prefix('api')->group(function () {
    Route::post('sessions/ping', [SessionController::class, 'ping']);
    Route::post('sessions/leave', [SessionController::class, 'leave']);
    Route::get('circuits', [CircuitController::class, 'index']);
    Route::post('circuits', [CircuitController::class, 'store']);
    Route::get('circuits/{circuit}', [CircuitController::class, 'show']);
    Route::post('circuits/{circuit}/join', [CircuitController::class, 'join']);
    Route::post('circuits/{circuit}/demo', [CircuitController::class, 'loadDemo']);
    Route::post('circuits/{circuit}/clear', [CircuitController::class, 'clear']);
    Route::post('circuits/{circuit}/components', [CircuitController::class, 'component']);
    Route::patch('components/{component}', [CircuitController::class, 'updateComponent']);
    Route::delete('components/{component}', [CircuitController::class, 'removeComponent']);
    Route::post('circuits/{circuit}/wires', [CircuitController::class, 'wire']);
    Route::delete('wires/{wire}', [CircuitController::class, 'removeWire']);
});
