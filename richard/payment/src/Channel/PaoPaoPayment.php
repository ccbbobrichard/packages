<?php

namespace App\Extendtions\Channel;

use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class PaoPaoPayment extends PaymentInterface
{

    private $recvid;
    private $api_key;
    private $pre_url = "https://fh14no2113fdl.pppay24.com/";

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'PaoPaoPayment');
        $this->pre_name = "跑跑支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("PaoPaoPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['recvid'])) $this->recvid = $params['recvid'];
            if(isset($params['api_key'])) $this->api_key = $params['api_key'];
        }
    }

    private function parsePaytype()
    {
        if($this->deposit_order->payment_id == 6){ //数字人民币
            return "数字人民币";
        }
        if($this->deposit_order->payment_id == 23){ //云闪付
            return "数字人民币";
        }
        if($this->deposit_order->payment_id == 22){ //银联扫码
            return "数字人民币";
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
            'recvid' => $this->recvid,
            'orderid' => $this->deposit_order->ordernumber,
            'amount' => $this->deposit_order->pay_amount,
            'paytypes' => $this->parsePaytype(),
            'notifyurl' => route('cashier.deposit.new.callback',['channel_id'=>$this->channel_id]),
            'memuid' => md5($this->deposit_order->mid)
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("createpay",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 1){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $data = json_decode($response['data'],true);
                $this->data['channel_pay_url'] = $data['navurl'];
                $this->data['channel_ordernumber'] = $data['id'];
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
       $response = Http::withHeaders(['Content-Type'=>"application/json"])->get($this->pre_url."getpay",['id'=>$order_id]);
        bob_newlog($this->pre_name."查询data",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 1){
                $data = json_decode($response['data'],true);
                if(isset($data['state']) && $data['state'] == 4){
                    return $data;
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
        $arrs = ['recvid' => $this->recvid,'orderid' => $arr['orderid'],'amount' => $arr['amount']];

        $md5str = "";
        foreach ($arrs as $key => $val) {
            $md5str = $md5str . $val;
        }
        bob_newlog("签名字符串",['str'=>$md5str.$this->api_key,'sign'=>md5($md5str.$this->api_key)],$this->filename);
        return md5($md5str.$this->api_key);
    }
}
