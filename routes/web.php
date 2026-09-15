<?php

use App\Http\Controllers\FileUploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/files', [FileUploadController::class, 'store'])->name('files.store');

Route::delete('/files/{file}', [FileUploadController::class, 'destroy'])->name('files.destroy');
