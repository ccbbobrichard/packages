<?php

namespace Richard\Payment\Http\Controllers;


use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\App;

class DepositCallbackController extends Controller
{

    public function index($channel)
    {
        if($channel){
            switch ($channel){
                case "cxpayment":
                    return App::make(CXPaymentCallbackService::class)->excute();
                    break;
                case "aipaypayment":
                    return App::make(AiPayPaymentCallbackService::class)->excute();
                    break;
                case "paopaopayment":
                    return App::make(PaoPaoPaymentCallbackService::class)->excute();
                    break;
                case "canglanpayment":
                    return App::make(CangLanPaymentCallbackService::class)->excute();
                    break;
                case "guyuepayment":
                    return App::make(GuYuePaymentCallbackService::class)->excute();
                    break;
                case "juyingpayment":
                    return App::make(JuyingPaymentCallbackService::class)->excute();
                    break;
            }
        }
        return "OK";

    }
}
