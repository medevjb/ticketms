<?php

use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\Customer\NotificationController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\Customer\TicketAttachmentController;
use App\Http\Controllers\Customer\TicketController;
use App\Http\Controllers\Customer\TicketConversationController;
use App\Http\Middleware\EnsureCustomerPortalAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')
    ->middleware(['auth', EnsureCustomerPortalAccess::class])
    ->name('customer.')
    ->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
        Route::get('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
            ->middleware('throttle:6,1')->name('profile.password');
        Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/tickets', [TicketController::class, 'store'])
            ->middleware('throttle:ticket-create')->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
        Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])->whereNumber('ticket')->name('tickets.edit');
        Route::put('/tickets/{ticket}', [TicketController::class, 'update'])->whereNumber('ticket')->name('tickets.update');
        Route::patch('/tickets/{ticket}/transition', [TicketController::class, 'transition'])->whereNumber('ticket')->name('tickets.transition');
        Route::post('/tickets/{ticket}/messages', [TicketConversationController::class, 'store'])
            ->whereNumber('ticket')->middleware('throttle:ticket-replies')->name('tickets.messages.store');
        Route::get('/ticket-attachments/{attachment}/download', [TicketAttachmentController::class, 'download'])
            ->whereNumber('attachment')->middleware('throttle:ticket-downloads')->name('ticket-attachments.download');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->whereUuid('notification')->name('notifications.read');
    });
