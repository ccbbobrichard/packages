<?php
use Illuminate\Support\Facades\Route;
use Richard\Payment\Http\Controllers\DepositCallbackController;
use Richard\Payment\Http\Controllers\TransferCallbackController;

Route::any("cashier/deposit/callback/{channel}", [DepositCallbackController::class, 'index'])->name('cashier.deposit.callback');
Route::any("cashier/transfer/callback/{channel}", [TransferCallbackController::class, 'index'])->name('cashier.transfer.callback');
