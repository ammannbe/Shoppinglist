<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\SyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// App shell: public and cacheable by the service worker, all data is loaded client side.
Route::view('/', 'app')->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware('auth')->group(function () {
    Route::get('me', fn (Request $request) => [
        'email' => $request->user()->email,
        'name' => $request->user()->name,
        'verified' => $request->user()->hasVerifiedEmail(),
        'locale' => $request->user()->locale,
    ])->name('me');

    Route::middleware('verified')->group(function () {
        Route::get('sync/{collection}/pull', [SyncController::class, 'pull'])->name('sync.pull');
        Route::post('sync/{collection}/push', [SyncController::class, 'push'])->name('sync.push');

        Route::post('lists/{list}/members', [MemberController::class, 'store'])->name('members.store');
        Route::delete('lists/{list}/members/{email}', [MemberController::class, 'destroy'])->name('members.destroy');
    });
});
