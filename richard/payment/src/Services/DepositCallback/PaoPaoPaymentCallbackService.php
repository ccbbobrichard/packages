<?php

namespace App\Services\DepositCallback;

use App\Extendtions\Channel\PaoPaoPayment;
use App\Models\DepositOrder;
use App\Services\Cache\Channel\ChannelWhiteIpByClassNameService;
use App\Services\Const\LogConstService;
use App\Services\DepositOrder\ConfirmPaySuccessService;
use App\Services\DepositOrderLog\CreateDepositOrderLogService;
use App\Services\IpWhite\CheckIpService;
use App\Traits\ServiceTraits;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class PaoPaoPaymentCallbackService
{
    use ServiceTraits;

    private $filename = "PaoPaoPaymentCallbackService";

    private $prename = "跑跑支付,";

    public function excute()
    {
        bob_newlog($this->prename."充值回调",['ip'=>request()->getClientIp(),'data'=>request()->all()],$this->filename);
        $ips = App::make(ChannelWhiteIpByClassNameService::class)->excute("PaoPaoPayment");
        $check = App::make(CheckIpService::class)->excute($ips);
        if($check) {
            $data = request()->all();
            if(isset($data['sign']) && isset($data['orderid'])){
                $sign = $data['sign'];
                DB::transaction(function ()use($data,$sign){
                    $order = DepositOrder::where('ordernumber',$data['orderid'])->where('status',3)->lockForUpdate()->first();
                    if($order){
                        $payment = new PaoPaoPayment(LogConstService::DEPOSIT_ORDER_LOG_PREFIEX.$order->id);
                        if($payment->checkSign(['orderid'=>$data['orderid'],'amount'=>$data['amount']],$sign)){
                            $result = $payment->queryDepositStatus($data['id']);
                            if(!empty($result)){
                                $confirmPaySuccessService = App::makeWith(ConfirmPaySuccessService::class,['filename'=>LogConstService::DEPOSIT_ORDER_LOG_PREFIEX.$order->id]);
                                $confirmPaySuccessService->excute($order->id,floatval($result['amount']));

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
