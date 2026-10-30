<?php

use App\Http\Controllers\LandingPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingPageController::class)->name('home');

require __DIR__.'/customer.php';
require __DIR__.'/admin.php';
