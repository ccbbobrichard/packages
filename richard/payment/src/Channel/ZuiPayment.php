<?php

namespace App\Extendtions\Channel;

use App\Extendtions\Channel\PaymentInterface;
use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class ZuiPayment extends PaymentInterface
{

    protected $mid;
    protected $api_key;

    private $pre_url = "http://lr5l29917mcs5hhsesrv.itnbpay.xyz/";

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'ZuiPayment');
        $this->pre_name = "最支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("ZuiPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['mid'])) $this->mid = $params['mid'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    public function parseChannel(){
        if($this->deposit_order->payment_id == 8){ //京东e卡
            return 8001;
        }
        if($this->deposit_order->payment_id == 9){ //转账码
            return 8016;
        }
        if($this->deposit_order->payment_id == 10){ //小额uid
            return 8006;
        }
        if($this->deposit_order->payment_id == 11){ //大额uid
            return 8008;
        }
        return;
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
            'mid' => $this->mid,
            'merOrderTid' => $this->deposit_order->ordernumber,
            'money' => $this->deposit_order->pay_amount,
            'channelCode' => $this->parseChannel(),
            'notifyUrl' => route('cashier.deposit.callback')
        ];
        $data['sign'] = $this->sign($data);
        $response = $this->fetchData("api/services/app/Api_PayOrder/CreateOrderPay",$data);
        if($response->successful()){
            if(isset($response['status']) && $response['status'] == 0){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"第三方返回成功参数",$response,"debug");
                $this->data['channel_pay_url'] = $response['result']['payUrl'];
                $this->data['channel_ordernumber'] = $response['result']['tid'];
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
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
        throw new \Exception('不支持此方法');
    }

    function queryDepositStatus($order_id)
    {
        $data = [
            'mid' => $this->mid,
            'merOrderTid' => $order_id
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/services/app/Api_PayOrder/QueryPayOrder",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['status']) && $response['status'] == 0){
                if(isset($response['result']) && isset($response['result']['payOrderStatus'])){
                    if($response['result']['payOrderStatus'] == 1){
                        return $response['result'];
                    }
                }
            }
        }
        return;
    }

    function queryTransferStatus($order_id)
    {
        throw new \Exception('不支持此方法');
    }

    private function fetchData($url,$data = [])
    {
        return Http::withHeaders(['Content-Type'=>"application/json"])->asForm()->post($this->pre_url.$url,$data);
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
        bob_newlog("签名字符串",['str'=>$md5str . "key=" . $this->api_key,'sign'=>strtoupper(md5($md5str.$this->api_key))],$this->filename);
        return strtoupper(md5($md5str.$this->api_key));
    }
}
