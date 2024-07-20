<?php

namespace Richard\Payment\Channel;

use App\Extendtions\Channel\PaymentInterface;
use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class AiPayPayment extends PaymentInterface
{

    private $mchKey;
    private $api_key;

    private $pre_url = "https://tsapi-aipay.tszfaa.com/";

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'AiPayPayment');
        $this->pre_name = "AiPay支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("AiPayPayment");
        $this->parseChannelParams();
    }

    function microsecond()
    {
        $t = explode(" ", microtime());
        $microsecond = round(round($t[1] . substr($t[0], 2, 3)));
        return $microsecond;
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['mchKey'])) $this->mchKey = $params['mchKey'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    private function parseProduct(){
        if($this->deposit_order->payment_id == 6){ //数字rmb
            return 1105;
        }
        if($this->deposit_order->payment_id == 19){ //支付宝小额uid
            return 3004;
        }
        if($this->deposit_order->payment_id == 22){ //银联扫码
            return 1107;
        }
        if($this->deposit_order->payment_id == 23){ //云闪付
            return 1104;
        }
        return 0;
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
            'mchKey' => $this->mchKey,
            'product' => $this->parseProduct(),
            'mchOrderNo' => $this->deposit_order->ordernumber,
            'amount' => $this->deposit_order->pay_amount * 100,
            'nonce' => bob_get_rand_str(8),
            'timestamp' => $this->microsecond(),
            'notifyUrl' => route('cashier.deposit.new.callback',['channel_id'=>$this->channel_id]),
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("api/v1/payment/init",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 200){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_pay_url'] = $response['data']['url']['payUrl'];
                $this->data['channel_ordernumber'] = $response['data']['serialOrderNo'];
                $this->data['expired_time'] = intval($response['data']['url']['expire']/1000);
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
            'mchKey' => $this->mchKey,
            'nonce' => bob_get_rand_str(8),
            'timestamp' => $this->microsecond(),
            'mchOrderNo' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/v1/payment/query",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 200){
                if(isset($response['data']) && isset($response['data']['payStatus'])){
                    if($response['data']['payStatus'] == 'SUCCESS'){
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
        $md5str = trim($md5str, "&");
        bob_newlog("签名字符串",['str'=>$md5str. $this->api_key,'sign'=>strtolower(md5($md5str.$this->api_key))],$this->filename);
        return strtolower(md5($md5str.$this->api_key));
    }
}
