<?php

namespace Richard\Payment\Http\Controllers;

use App\Http\Controllers\Api;
use App\Services\Cache\Channel\ClassNameByChannelIdService;
use App\Services\TransferCallback\JuyingPaymentCallbackService;
use Illuminate\Support\Facades\App;

class TransferCallbackController extends Api\ApiController
{
    public function index($channel_id = 0)
    {
        if($channel_id > 0){
            $classname = App::make(ClassNameByChannelIdService::class)->excute($channel_id);
            switch ($classname){
                case "JuyingPayment":
                    return App::make(JuyingPaymentCallbackService::class)->excute();
                    break;
            }
        }
        return "OK";
    }
}
