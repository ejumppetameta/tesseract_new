<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdfOcrController;

Route::get('/', function () {
    return view('upload'); // This returns the Blade view at resources/views/upload.blade.php
});

Route::post('/process-pdf', [PdfOcrController::class, 'process']);
