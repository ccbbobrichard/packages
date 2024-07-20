<?php
namespace Richard\Payment;
class PaymentServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        include __DIR__.'/routes.php';
    }

    public function register()
    {

    }
}
