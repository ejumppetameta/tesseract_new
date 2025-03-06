<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdfOcrController;
use App\Http\Controllers\PdfOcrControllerCreditSense;

Route::get('/', function () {
    return view('upload'); // This returns the Blade view at resources/views/upload.blade.php
});

// Route for processing public bank statements
Route::post('/process-pdf-public', [PdfOcrController::class, 'process'])->name('process-pdf-public');

// Route for processing Credit Sense–style PDFs
Route::post('/process-pdf-credit-sense', [PdfOcrControllerCreditSense::class, 'process'])->name('process-pdf-credit-sense');

