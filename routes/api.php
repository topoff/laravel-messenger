<?php

use Illuminate\Support\Facades\Route;
use Topoff\Messenger\Http\Controllers\MailTrackingController;
use Topoff\Messenger\Http\Controllers\MailTrackingSnsController;
use Topoff\Messenger\Http\Controllers\VonageDlrController;

$routeConfig = config('messenger.tracking.route', []);
Route::group($routeConfig, function (): void {
    Route::get('t/{hash}', [MailTrackingController::class, 'open'])->name('messenger.tracking.open');
    Route::get('n', [MailTrackingController::class, 'click'])->name('messenger.tracking.click')->middleware('signed');
    Route::post('sns', [MailTrackingSnsController::class, 'callback'])->name('messenger.tracking.sns');
    Route::match(['get', 'post'], 'vonage-dlr', [VonageDlrController::class, 'callback'])->name('messenger.tracking.vonage-dlr');
});
