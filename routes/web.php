<?php

use App\Http\Controllers\AgentInvitationController;
use App\Http\Controllers\LandingPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingPageController::class)->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/agent-invitations/{token}', [AgentInvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{64}')->name('agent-invitations.show');
    Route::post('/agent-invitations/{token}', [AgentInvitationController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:5,1')->name('agent-invitations.store');
});

require __DIR__.'/customer.php';
require __DIR__.'/admin.php';
