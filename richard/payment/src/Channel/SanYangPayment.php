<?php

namespace App\Extendtions\Channel;

use App\Models\ChannelAccount;
use App\Models\DepositOrder;
use App\Models\TransferOrder;
use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use App\Services\DepositOrderLog\CreateDepositOrderLogService;
use App\Services\TransferOrderLog\CreateTransferOrderLogService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class SanYangPayment  extends PaymentInterface
{
    private $pre_url = "http://api.sunpay101.com/";

    private $mer_id;

    private $api_key;

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'SanYangPayment');
        $this->pre_name = "三阳支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("SanYangPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['mer_id'])) $this->mer_id = $params['mer_id'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    private function parseGateway(){
        if($this->deposit_order->payment_id == 15){ //微信ios小额原生
            return 980;
        }
        if($this->deposit_order->payment_id == 8){ //京东e卡
            return 982;
        }
        if($this->deposit_order->payment_id == 16){ //QQ群红包
            return 994;
        }
        if($this->deposit_order->payment_id == 17){ //云闪付红包
            return 1006;
        }
        if($this->deposit_order->payment_id == 6){ //数字rmb
            return 1012;
        }
        return 0;
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
            'mer_id' => $this->mer_id,
            'order_id' => $this->deposit_order->ordernumber,
            'notify' => route('cashier.deposit.callback'),
            'callback' => route('cashier.callback.url'),
            'amount' => intval($this->deposit_order->pay_amount),
            'gateway' => $this->parseGateway(),
            'realname' => $this->deposit_order->pay_name,
            'player_ip' => "47.57.70.202"
        ];

        $data['sign'] = $this->sign(Arr::except($data,['player_ip','realname']));
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("api/pay/",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_pay_url'] = $response['data'];
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
            'mer_id' => $this->mer_id,
            'order_id' => $order_id,
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("api/status/",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 0){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 0){
                        return $response['data'];
                    }
                }
            }
        }
        return;
    }

    public function transfer($transfer_order_id = 0)
    {
       throw new \Exception('不支持此方法');
    }

    public function queryTransferStatus($order_id)
    {
        throw new \Exception('不支持此方法');
    }

    private function fetchData($url,$data = [])
    {
        return Http::asForm()->post($this->pre_url.$url,$data);
    }

    public function checkSign($data,$sign){
        $self = $this->sign($data);
        if($self == $sign) return true;
        bob_newlog("签名错误",['sign'=>$sign,'self' => $self],$this->filename);
        return;
    }


    public function sign($arr)
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
