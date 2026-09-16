<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\FileUploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FileUploadController::class, 'create'])->name('upload.create');

Route::post('/files', [FileUploadController::class, 'store'])->name('files.store');

Route::get('/files', [FileController::class, 'index'])->name('files.index');

Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
