<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::post('/upload-chunk', [FileController::class, 'uploadChunk']);

Route::prefix('api/uploads')->group(function () {
    Route::post('/', [UploadController::class, 'start']);
    Route::get('/{id}', [UploadController::class, 'show']);
    Route::post('/{id}/chunks/{index}', [UploadController::class, 'chunk'])->whereNumber('index');
    Route::post('/{id}/complete', [UploadController::class, 'complete']);
    Route::delete('/{id}', [UploadController::class, 'cancel']);
});
