<?php

namespace App\Extendtions\Channel;

use App\Extendtions\Channel\PaymentInterface;
use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class DaHePayment extends PaymentInterface
{

    private $mchId;

    private $api_key;

    private $app_id;

    private $pre_url = "http://16.163.129.44:56700/";

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'DaHePayment');
        $this->pre_name = "大河支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("DaHePayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['mchId'])) $this->mchId = $params['mchId'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
            if(isset($params['app_id'])) $this->app_id = $params['app_id'];
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
            'mchId' => $this->mchId,
            'productId' => 8058,
            'mchOrderNo' => $this->deposit_order->ordernumber,
            'currency' => 'cny',
            'amount' => $this->deposit_order->pay_amount * 100,
            'notifyUrl' => route('cashier.deposit.callback'),
            'subject' => '大河支付',
            'body' => '大河支付',
            'reqTime' => date('YmdHis'),
            'version' => '1.0'
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("api/pay/create_order",$data);
        if($response->successful()){
            if(isset($response['retCode']) && $response['retCode'] == 0){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_ordernumber'] = $response['payOrderId'];
                $this->data['channel_pay_url'] = $response['payJumpUrl'];
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
            'mchId' => $this->mchId,
            'appId' => $this->app_id,
            'mchOrderNo' => $order_id,
            'reqTime' => date('YmdHis'),
            'version' => '1.0',
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/pay/query_order",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['retCode']) && $response['retCode'] == 0){
                if(isset($response['status']) && isset($response['status'])){
                    if($response['status'] == 2){
                        return $response;
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
        bob_newlog("签名字符串",['str'=>$md5str . "key=" . $this->api_key,'sign'=>strtoupper(md5($md5str."key=".$this->api_key))],$this->filename);
        return strtoupper(md5($md5str."key=".$this->api_key));
    }
}
