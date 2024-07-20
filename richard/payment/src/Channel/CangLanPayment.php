<?php

namespace Richard\Payment\Channel;

use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class CangLanPayment extends BasePayment
{
    protected $AccessKey;
    protected $AccessSecret;

    protected $pre_url = "https://api.dscloud88.com/";

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'CangLanPayment');
        $this->pre_name = "沧澜支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("CangLanPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['AccessKey'])) $this->AccessKey = $params['AccessKey'];
            if(isset($params['AccessSecret'])) $this->AccessSecret = $params['AccessSecret'];
        }
    }

    public function parsePayCode(){
        if($this->deposit_order->payment_id == 6){ //数字人民币
            return 112;
        }
        if($this->deposit_order->payment_id == 10){ //小额uid
            return 116;
        }
        if($this->deposit_order->payment_id == 22){ //银联扫码
            return 118;
        }
        if($this->deposit_order->payment_id == 17){ //云闪付红包
            return 004;
        }
        if($this->deposit_order->payment_id == 20){ //支付宝抗投原生
            return 120;
        }
        if($this->deposit_order->payment_id == 26){ //云闪付原生
            return 005;
        }
        if($this->deposit_order->payment_id == 27){ //小额卡卡
            return 002;
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
            'AccessKey' => $this->AccessKey,
            'Timestamp' => time(),
            'OrderNo' => $this->deposit_order->ordernumber,
            'Amount' => $this->deposit_order->pay_amount * 100,
            'PayCode' => $this->parsePayCode(),
            'CallbackUrl' => route('cashier.deposit.new.callback',['channel_id'=>$this->channel_id]),
            'GoodsName' => "沧澜支付",
            'PayerName' => '沧澜支付',
            'PayerNo' => '',
            'PayerAddress' => '',
            'Ext' => '',
            'NonceStr' => bob_get_rand_str(8),
            'Version' => '3.0'
        ];
        $data['Sign'] = $this->sign($data);
        $response = $this->fetchData("mapi/submit_order",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 200){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"第三方返回成功参数",$response,"debug");
                $this->data['channel_pay_url'] = $response['data']['PayUrl'];
                $this->data['channel_ordernumber'] = $response['data']['OrderNo'];
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
            'Timestamp' => time(),
            'AccessKey' => $this->AccessKey,
            'OrderNo' => $order_id,
            'NonceStr' => bob_get_rand_str(8),
            'Version' => '3.0'
        ];
        $data['Sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("mapi/select_order",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 200){
                if(isset($response['data']) && isset($response['data']['Status'])){
                    if($response['data']['Status'] == 1){
                        return $response['data'];
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


    function fetchData($url,$data = [])
    {
        return Http::withHeaders(['Content-Type'=>"application/json"])->asForm()->post($this->pre_url.$url,$data);
    }

    function sign($arr)
    {
        ksort($arr);
        $md5str = "";
        foreach ($arr as $key => $val) {
            $md5str = $md5str . $key . $val;
        }
        bob_newlog("签名字符串",['str'=>$md5str . $this->AccessSecret,'sign'=>md5($md5str.$this->AccessSecret)],$this->filename);
        return md5($md5str.$this->AccessSecret);
    }
}
