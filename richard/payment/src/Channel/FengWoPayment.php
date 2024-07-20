<?php

namespace App\Extendtions\Channel;

use App\Extendtions\Channel\PaymentInterface;
use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class FengWoPayment extends PaymentInterface
{
    private $pre_url = "http://api.icmbapi.com/";

    private $api_key;

    private $sign_key;

    private $mch_id;

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'FengWoPayment');
        $this->pre_name = "蜂窝支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("FengWoPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['mch_id'])) $this->mch_id = $params['mch_id'];
            if(isset($params['sign_key'])) $this->sign_key = $params['sign_key'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    private function parseGateway(){
        if($this->deposit_order->payment_id == 12){ //支付宝小荷包
            return "alipayx";
        }
        if($this->deposit_order->payment_id == 13){ //钉钉
            return "dingding";
        }
        if($this->deposit_order->payment_id == 14){ //抖音
            return "douyin";
        }
        if($this->deposit_order->payment_id == 8){ //京东e卡
            return "ecard";
        }
        if($this->deposit_order->payment_id == 6){ //数字人民币
            return "e_rmb";
        }
    }

    function deposit($deposit_order_id)
    {
        $this->deposit_order_id = $deposit_order_id;
        try {
            $this->getDepositOrderInfo();
            $this->getChannelAccount($this->deposit_order->pay_amount,1,$this->channel_id);
        }catch (\Exception){
            return $this->data;
        }
        $data = [
            'mid' => $this->mch_id,
            'time' => time(),
            'notify_url' => route('cashier.deposit.callback'),
            'amount' => $this->deposit_order->pay_amount,
            'gateway' => $this->parseGateway(),
            'ip' => "47.57.70.202",
            'currency' => 'CNY',
            'order_no' => $this->deposit_order->ordernumber,
            'data_type' => ''
        ];

        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("api/v1/deposits",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 200){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_ordernumber'] = $response['data']['no'];
                $this->data['channel_pay_url'] = $response['data']['url'];
            }else{
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"请求第三方失败",$response,"debug");
            }
        }else{
            $this->createDepositOrderLogService->excute($this->deposit_order->id,"请求第三方失败",$response,"debug");
        }
        return $this->data;
    }

    function transfer($transfer_order_id)
    {
        $this->transfer_order_id = $transfer_order_id;
        try {
            $this->getTransferOrderInfo();
            $this->getChannelAccount($this->transfer_order->amount,2,$this->channel_id);
        }catch (\Exception){
            return $this->data;
        }
        $data = [
            'mid' => $this->mch_id,
            'time' => time(),
            'order_no' => $this->transfer_order->ordernumber,
            'notify_url' => route('cashier.transfer.callback'),
            'amount' => $this->transfer_order->amount,
            'holder_name' => $this->transfer_order->holder_name,
            'card_no' => $this->transfer_order->card_no,
            'bank_code' => $this->transfer_order->bank_code,
            'currency' => 'CNY',
            'ip' => "47.57.70.202"
        ];
        $data['sign'] = $this->sign($data);
        $response = $this->fetchData("api/v1/transfers",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 200){
                $this->createTransferOrderLogService->excute($this->transfer_order->id,"第三方返回成功参数",$response,"debug");
                $this->data['channel_ordernumber'] = $response['data']['no'];
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

    function queryDepositStatus($order_id)
    {
        $data = [
            'mid' => $this->mch_id,
            'time' => time(),
            'order_no' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/v1/deposits/query",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 200){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 'succeeded'){
                        return $response['data'];
                    }
                }
            }
        }
        return;
    }

    function queryTransferStatus($order_id)
    {
        $data = [
            'mid' => $this->mch_id,
            'time' => time(),
            'order_id' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/v1/transfers/query",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 200){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 'succeeded'){
                        return $response['data'];
                    }
                }
            }
        }
        return;
    }

    private function fetchData($url,$data = [])
    {
        return Http::withHeaders(['Content-Type'=>"application/json","Authorization"=>"api-key ".$this->api_key,"Accept" => "application/problem+json"])->post($this->pre_url.$url,$data);
    }

    public function checkSign($data,$sign){
        $self = $this->sign($data);
        if($self == $sign) return true;
        bob_newlog("签名错误",['sign'=>$sign,'self' => $self],$this->filename);
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
        $md5str = trim($md5str, "&");
        bob_newlog("签名字符串",['str'=>$md5str . "key=" . $this->api_key,'sign'=>base64_encode(hash_hmac("sha1",$md5str,$this->api_key,true))],$this->filename);
        return base64_encode(hash_hmac("sha1",$md5str,$this->sign_key,true));
    }
}
