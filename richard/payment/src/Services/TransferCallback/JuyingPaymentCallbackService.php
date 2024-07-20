<?php

namespace App\Services\TransferCallback;

use App\Extendtions\Channel\JuyingPayment;
use App\Models\TransferOrder;
use App\Services\Cache\Channel\ChannelWhiteIpByClassNameService;
use App\Services\Const\LogConstService;
use App\Services\IpWhite\CheckIpService;
use App\Services\SettlementOrder\SettlementOrderSuccessService;
use App\Services\TransferOrder\TransferOrderSuccessService;
use App\Services\TransferOrderLog\CreateTransferOrderLogService;
use App\Traits\ServiceTraits;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class JuyingPaymentCallbackService
{
    use ServiceTraits;

    private $filename = "JuyingPaymentCallbackService";

    private $prename = "聚盈支付,";

    public function excute()
    {
        bob_newlog($this->prename."代付回调",['ip'=>request()->getClientIp(),'data'=>request()->all()],$this->filename);
        $ips = App::make(ChannelWhiteIpByClassNameService::class)->excute("JuyingPayment");
        $check = App::make(CheckIpService::class)->excute($ips);
        if($check){
            $data = request()->all();
            if(isset($data['sign']) && isset($data['orderNo'])){
                $sign = $data['sign'];
                DB::transaction(function ()use($data,$sign){
                    $order = TransferOrder::where('ordernumber',$data['orderNo'])->where('status',2)->lockForUpdate()->first();
                    if($order){
                        $payment = new JuyingPayment(LogConstService::TRANSFER_ORDER_LOG_PREFIEX.$order->id);
                        if($payment->checkSign(Arr::only($data,['mch_id','nonce_str','orderNo','score','timeStamp']),$sign)){
                            $result = $payment->queryTransferStatus($data['tradeNo']);
                            if(!empty($result)){
                                if($order->type == 0){
                                    $transferOrderSuccessService = App::makeWith(TransferOrderSuccessService::class,['filename'=>LogConstService::TRANSFER_ORDER_LOG_PREFIEX.$order->id]);
                                    $transferOrderSuccessService->excute($order->id,floatval($result['score']));
                                }

                                if($order->type == 1){
                                    $settlementOrderSuccessService = App::makeWith(SettlementOrderSuccessService::class,['filename'=>LogConstService::TRANSFER_ORDER_LOG_PREFIEX.$order->id]);
                                    $settlementOrderSuccessService->excute($order->id,floatval($result['score']));
                                }

                                $createTransferOrderLogService = App::makeWith(CreateTransferOrderLogService::class,['filename'=>LogConstService::TRANSFER_ORDER_LOG_PREFIEX.$order->id]);
                                $createTransferOrderLogService->excute($order->id,"第三方回调成功",$result);
                            }else{
                                $order->status = 3;
                                $order->remark = "第三方支付失败";
                                $order->save();

                                $createTransferOrderLogService = App::makeWith(CreateTransferOrderLogService::class,['filename'=>LogConstService::TRANSFER_ORDER_LOG_PREFIEX.$order->id]);
                                $createTransferOrderLogService->excute($order->id,"第三方回调，支付失败",$result);
                            }
                        }
                    }
                });
            }
            return "success";
        }
        bob_newlog($this->prename."代付回调失败",['ip'=>request()->getClientIp(),'ips'=>$ips,'error'=>"ip不在白名单"],$this->filename);
        return;
    }
}
