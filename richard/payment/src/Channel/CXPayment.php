<?php

namespace App\Extendtions\Channel;

use App\Extendtions\Channel\PaymentInterface;
use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class CXPayment extends PaymentInterface
{
    private $mchId;
    private $api_key;
    private $pre_url = "https://pay.fwaqo.top/";

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'CXPayment');
        $this->pre_name = "CX支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("CXPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['mchId'])) $this->mchId = $params['mchId'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    private function paraseWaycode(){
        if($this->deposit_order->payment_id == 18){ //支付宝大额uid
            return 104;
        }
        if($this->deposit_order->payment_id == 6){ //数字rmb
            return 105;
        }
        if($this->deposit_order->payment_id == 19){ //支付宝小额uid
            return 101;
        }
        if($this->deposit_order->payment_id == 21){ //手机银行H5
            return 206;
        }
        if($this->deposit_order->payment_id == 20){ //支付宝原生
            return 666;
        }
        return 0;
    }

    function microsecond()
    {
        $t = explode(" ", microtime());
        $microsecond = round(round($t[1] . substr($t[0], 2, 3)));
        return $microsecond;
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
            'mchId' => $this->mchId,
            'wayCode' => $this->paraseWaycode(),
            'subject' => "CX支付",
            'outTradeNo' => $this->deposit_order->ordernumber,
            'amount' => $this->deposit_order->pay_amount * 100,
            'clientIp' => '47.57.70.202',
            'notifyUrl' => route('cashier.deposit.new.callback',['channel_id'=>$this->channel_id]),
            'reqTime' => $this->microsecond()
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("api/pay/unifiedorder",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_pay_url'] = $response['data']['payUrl'];
                $this->data['channel_ordernumber'] = $response['data']['tradeNo'];
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
        throw new \Exception('不支持代付');
    }

    function queryDepositStatus($order_id)
    {
        $data = [
            'mchId' => $this->mchId,
            'reqTime' => $this->microsecond(),
            'outTradeNo' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/pay/query",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                if(isset($response['data']) && isset($response['data']['state'])){
                    if($response['data']['state'] == 1){
                        return $response['data'];
                    }
                }
            }
        }
        return;
    }

    function queryTransferStatus($order_id)
    {
        throw new \Exception('不支持代付');
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
        bob_newlog("签名字符串",['str'=>$md5str . "key=" . $this->api_key,'sign'=>strtolower(md5($md5str."key=".$this->api_key))],$this->filename);
        return strtolower(md5($md5str."key=".$this->api_key));
    }
}
