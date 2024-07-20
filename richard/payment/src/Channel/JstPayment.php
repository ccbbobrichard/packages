<?php

namespace App\Extendtions\Channel;

use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class JstPayment extends PaymentInterface
{

    private $pre_url = "https://apii.dkk888.com/";

    public $pay_customer_id;

    public $pay_channel_id = 5067;

    public $api_key;



    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'JstPayment');
        $this->pre_name = "JST支付宝，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("JstPayment");
        $this->parseChannelParams();
    }


    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['pay_customer_id'])) $this->pay_customer_id = $params['pay_customer_id'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    //代收
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
            'pay_customer_id' => $this->pay_customer_id,
            'pay_apply_date' => time(),
            'pay_order_id' => $this->deposit_order->ordernumber,
            'pay_notify_url' => route('cashier.deposit.callback'),
            'pay_amount' => $this->deposit_order->pay_amount,
            'pay_channel_id' => $this->pay_channel_id,
            'user_name' => $this->deposit_order->pay_name
        ];
        $data['pay_md5_sign'] = $this->sign($data);
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("api/pay_order",$data);
        if($response->successful()){
           if(isset($response['code']) && $response['code'] == 0){
               $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
               $this->data['channel_id'] = $this->channel_id;
               $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
               $this->data['channel_pay_url'] = $response['data']['view_url'];
               $this->data['channel_ordernumber'] = $response['data']['transaction_id'];
               $this->data['qrCodeUrl'] = $response['data']['qr_url'];
               $this->data['expired_time'] = strtotime($response['data']['expired']);
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
            'pay_customer_id' => $this->pay_customer_id,
            'pay_apply_date' => time(),
            'pay_order_id' => $order_id,
        ];
        $data['pay_md5_sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/query_transaction",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 2){
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
            'pay_customer_id' => $this->pay_customer_id,
            'pay_apply_date' => time(),
            'pay_order_id' => $this->transfer_order->ordernumber,
            'pay_notify_url' => route('cashier.transfer.callback'),
            'pay_amount' => $this->transfer_order->amount,
            'pay_account_name' => $this->transfer_order->holder_name,
            'pay_card_no' => $this->transfer_order->card_no,
            'pay_bank_name' => $this->transfer_order->bank->name,
        ];
        $data['pay_md5_sign'] = $this->sign($data);
        $response = $this->fetchData("api/payments/pay_order",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                $this->createTransferOrderLogService->excute($this->transfer_order->id,"第三方返回成功参数",$response,"debug");
                $this->data['channel_ordernumber'] = $response['data']['transaction_id'];
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
            'pay_customer_id' => $this->pay_customer_id,
            'pay_apply_date' => time(),
            'pay_order_id' => $order_id,
        ];
        $data['pay_md5_sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/payments/query_transaction",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 2){
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
        bob_newlog("签名字符串",['str'=>$md5str . "key=" . $this->api_key,'sign'=>strtoupper(md5($md5str . "key=" . $this->api_key))],$this->filename);
        return strtoupper(md5($md5str . "key=" . $this->api_key));
    }
}
