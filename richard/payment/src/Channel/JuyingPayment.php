<?php

namespace App\Extendtions\Channel;


use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use MrBrownNL\RandomNicknameGenerator\RandomNicknameGenerator;

class JuyingPayment  extends PaymentInterface
{
    private $pre_url = "https://api.colorwin.com/";

    private $mch_id;

    private $api_key;

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'JuyingPayment');
        $this->pre_name = "聚盈支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("JuyingPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['mch_id'])) $this->mch_id = $params['mch_id'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    private function parsePayment()
    {
        if($this->deposit_order->payment_id == 3){
            return "8001";
        }
        if($this->deposit_order->payment_id == 2){
            return "8003";
        }
        if($this->deposit_order->payment_id == 5){
            return "8004";
        }
        return;
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
        $nickNameGenerator = new RandomNicknameGenerator();
        $data = [
            'mch_id' => $this->mch_id,
            'nonce_str' => bob_get_rand_str(10),
            'timeStamp' => strval(time()),
            'orderNo' => $this->deposit_order->ordernumber,
            'notify_url' => route('cashier.deposit.new.callback',['channel_id'=>$this->channel_id]),
            'score' => strval($this->deposit_order->pay_amount*100),
            'userName' => $nickNameGenerator->generate(),
            'gateway' => $this->parsePayment()
        ];

        $data['sign'] = $this->sign(Arr::except($data,['gateway','notify_url']));
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("api/collection",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_pay_url'] = $response['data']['url'];
                $this->data['channel_ordernumber'] = $response['data']['tradeNo'];
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
            'mch_id' => $this->mch_id,
            'nonce_str' => bob_get_rand_str(10),
            'timeStamp' => strval(time()),
            'tradeNo' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/collection/order",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                if(isset($response['data']) && isset($response['data']['tradeState'])){
                    if($response['data']['tradeState'] == 'SUCCESS'){
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
            'mch_id' => $this->mch_id,
            'nonce_str' => bob_get_rand_str(10),
            'timeStamp' => strval(time()),
            'orderNo' => $this->transfer_order->ordernumber,
            'notify_url' => route('cashier.transfer.new.callback',['channel_id'=>$this->channel_id]),
            'score' => strval($this->transfer_order->amount*100),
            'userName' => $this->transfer_order->holder_name,
            'cardId' => $this->transfer_order->card_no,
            'bankName' => $this->transfer_order->bank->name,
            'subName' => $this->transfer_order->bank_branch,
        ];
        $data['sign'] = $this->sign(Arr::only($data,['mch_id','nonce_str','orderNo','timeStamp']));
        $response = $this->fetchData("api/payment",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                $this->createTransferOrderLogService->excute($this->transfer_order->id,"第三方返回成功参数",$response,"debug");
                $this->data['channel_ordernumber'] = $response['data']['tradeNo'];
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
            'mch_id' => $this->mch_id,
            'nonce_str' => bob_get_rand_str(10),
            'timeStamp' => strval(time()),
            'tradeNo' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/payment/order",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                if(isset($response['data']) && isset($response['data']['tradeState'])){
                    if($response['data']['tradeState'] == 'SUCCESS'){
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
        bob_newlog("签名字符串",['str'=>$md5str . "key=" . $this->api_key,'sign'=>md5($md5str . "key=" . $this->api_key)],$this->filename);
        return md5($md5str . "key=" . $this->api_key);
    }
}
