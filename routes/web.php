<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContentItemController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChannelController::class, 'index'])->name('channels.index');
Route::get('/channels/create', [ChannelController::class, 'create'])->name('channels.create');
Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
Route::get('/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
Route::put('/channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
Route::post('/channels/{channel}/toggle', [ChannelController::class, 'toggle'])->name('channels.toggle');
Route::post('/channels/{channel}/make-now', [ChannelController::class, 'makeNow'])->name('channels.make-now');
Route::delete('/channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');

Route::get('/items/{item}/media/{kind}', [ContentItemController::class, 'media'])->whereIn('kind', ['video', 'thumb'])->name('items.media');
Route::post('/items/{item}/skip', [ContentItemController::class, 'skip'])->name('items.skip');

Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
Route::post('/accounts/facebook', [AccountController::class, 'facebookPages'])->name('accounts.facebook.pages');
Route::post('/accounts/facebook/store', [AccountController::class, 'facebookStore'])->name('accounts.facebook.store');
Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
