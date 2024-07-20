<?php

namespace App\Extendtions\Channel;

use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class FGPayment  extends PaymentInterface
{

    private $pre_url = "https://fuguo.link/ports-v1/";

    private $api_key;

    private $api_id;

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'FGPayment');
        $this->pre_name = "富国支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("FGPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['api_id'])) $this->api_id = $params['api_id'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    public function deposit($deposit_order_id = 0)
    {
        $this->deposit_order_id = $deposit_order_id;
        try {
            $this->getDepositOrderInfo();
            $this->getChannelAccount($this->deposit_order->pay_amount,1,$this->channel_id);
        }catch (\Exception){
            return $this->data;
        }
        $data = [
            'appId' => $this->api_id,
            'channelId' => 2,
            'returnUrl' => route('cashier.callback.url'),
            'callbackUrl' => route('cashier.deposit.callback'),
            'actionValue' => $this->deposit_order->pay_amount,
            'accountName' => $this->deposit_order->pay_name,
            'currency' => 'cny',
            'outOrderId' => $this->deposit_order->ordernumber
        ];

        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("recharge",$data);
        if($response->successful()){
            if(isset($response['result']) && $response['result'] ==1){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_pay_url'] = $response['url'];
                $this->data['channel_ordernumber'] = $response['transactionId'];
            }else{
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"请求第三方失败",$response,"debug");
            }
        }else{
            $this->createDepositOrderLogService->excute($this->deposit_order->id,"请求第三方失败",$response,"debug");
        }
        return $this->data;
    }

    public function queryDepositStatus($order_id)
    {
        $data = [
            'appId' => $this->api_id,
            'nonceStr' => bob_get_rand_str(10),
            'outOrderId' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("recharge-single-order-query",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['result']) && $response['result'] == 1){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 1){
                        return $response['data'];
                    }
                }
            }
        }
        return;
    }

    public function transfer($transfer_order_id = 0)
    {
        $this->transfer_order_id = $transfer_order_id;
        try {
            $this->getTransferOrderInfo();
            $this->getChannelAccount($this->transfer_order->amount,2,$this->channel_id);
        }catch (\Exception){
            return $this->data;
        }
        $data = [
            'appId' => $this->api_id,
            'channelId' => 30,
            'outOrderId' => $this->transfer_order->ordernumber,
            'callbackUrl' => route('cashier.transfer.callback'),
            'actionValue' => $this->transfer_order->amount,
            'ownerName' => $this->transfer_order->holder_name,
            'cardNumber' => $this->transfer_order->card_no,
            'bankName' => $this->transfer_order->bank->name,
            'currency' => 'CNY'
        ];
        $data['sign'] = $this->sign($data);
        $response = $this->fetchData("withdraw",$data);
        if($response->successful()){
            if(isset($response['result']) && $response['result'] == 1){
                $this->createTransferOrderLogService->excute($this->transfer_order->id,"第三方返回成功参数",$response,"debug");
                $this->data['channel_ordernumber'] = $response['transactionId'];
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
            }else{
                $this->createTransferOrderLogService->excute($this->transfer_order->id,"请求第三方失败",$response,"debug");
            }
        }else{
            $this->createTransferOrderLogService->excute($this->transfer_order->id,"请求第三方失败",$response,"debug");
        }
        return $this->data;
    }


    public function queryTransferStatus($order_id)
    {
        $data = [
            'appId' => $this->api_id,
            'nonceStr' => bob_get_rand_str(10),
            'outOrderId' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("withdraw-single-order-query",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['result']) && $response['result'] == 1){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 1){
                        return $response['data'];
                    }
                }
            }
        }
        return;
    }

    private function fetchData($url,$data = [])
    {
        return Http::withHeaders(['Content-Type'=>"application/json"])->post($this->pre_url.$url,$data);
    }

    public function checkSign($data,$sign){
        if($this->sign($data) == $sign) return true;
        return;
    }


    private function sign($arr)
    {
        ksort($arr);
        $md5str = "";
        foreach ($arr as $key => $val) {
            if ($val != null && $val != "") {
                $md5str = $md5str . $key . "=" . $val . "&";
            }
        }
        bob_newlog("签名字符串",['str'=>$md5str . "key=" . $this->api_key,'sign'=>strtolower(md5($md5str."key=".$this->api_key))],$this->filename);
        return strtolower(md5($md5str."key=".$this->api_key));
    }
}
