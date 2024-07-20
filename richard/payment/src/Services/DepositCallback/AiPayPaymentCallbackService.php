<?php

namespace Richard\Payment\Services\DepositCallback;

use App\Extendtions\Channel\AiPayPayment;
use App\Models\DepositOrder;
use App\Services\Cache\Channel\ChannelWhiteIpByClassNameService;
use App\Services\Const\LogConstService;
use App\Services\DepositOrder\ConfirmPaySuccessService;
use App\Services\DepositOrderLog\CreateDepositOrderLogService;
use App\Services\IpWhite\CheckIpService;
use App\Traits\ServiceTraits;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class AiPayPaymentCallbackService
{
    use ServiceTraits;

    private $filename = "AiPayPaymentCallbackService";

    private $prename = "AiPay支付,";

    public function excute()
    {
        bob_newlog($this->prename."充值回调",['ip'=>request()->getClientIp(),'data'=>request()->all()],$this->filename);
        $ips = App::make(ChannelWhiteIpByClassNameService::class)->excute("AiPayPayment");
        $check = App::make(CheckIpService::class)->excute($ips);
        if($check) {
            $data = request()->all();
            if(isset($data['sign']) && isset($data['mchOrderNo'])){
                $sign = $data['sign'];
                unset($data['sign']);
                DB::transaction(function ()use($data,$sign){
                    $order = DepositOrder::where('ordernumber',$data['mchOrderNo'])->where('status',3)->lockForUpdate()->first();
                    if($order){
                        $payment = new AiPayPayment(LogConstService::DEPOSIT_ORDER_LOG_PREFIEX.$order->id);
                        if($payment->checkSign($data,$sign)){
                            $result = $payment->queryDepositStatus($data['mchOrderNo']);
                            if(!empty($result)){
                                $confirmPaySuccessService = App::makeWith(ConfirmPaySuccessService::class,['filename'=>LogConstService::DEPOSIT_ORDER_LOG_PREFIEX.$order->id]);
                                $confirmPaySuccessService->excute($order->id,floatval($result['realAmount'])/100);

                                $createTransferOrderLogService = App::makeWith(CreateDepositOrderLogService::class,['filename'=>LogConstService::DEPOSIT_ORDER_LOG_PREFIEX.$order->id]);
                                $createTransferOrderLogService->excute($order->id,"第三方回调成功",$result);
                            }
                        }
                    }
                });
            }
            return "SUCCESS";
        }
        bob_newlog($this->prename."充值回调失败",['ip'=>request()->getClientIp(),'ips'=>$ips,'error'=>"ip不在白名单"],$this->filename);
        return;
    }
}
