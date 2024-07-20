<?php

namespace App\Extendtions\Channel;

use App\Extendtions\Channel\PaymentInterface;
use App\Services\Cache\Channel\ChannelIdByClassNameService;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class QingTianPayment extends PaymentInterface
{

    protected $mch_id;
    protected $api_key_deposit;
    protected $api_key_transfer;

    private $pre_url = "https://vip2.asfd.xyz/";

    function __construct($filename = "")
    {
        parent::__construct($filename ?: 'QingTianPayment');
        $this->pre_name = "晴天支付，";
        $this->channel_id = App::make(ChannelIdByClassNameService::class)->excute("QingTianPayment");
        $this->parseChannelParams();
    }

    public function parseChannelParams()
    {
        $result = App::make(CacheLastChannelAccountInfoService::class)->excute($this->channel_id);
        if(!empty($result) &&  isset($result['params_format']) && !empty($result['params_format'])){
            $params = $result['params_format'];
            if(isset($params['mch_id'])) $this->mch_id = $params['mch_id'];
            if(isset($params['api_key_deposit'])) $this->api_key_deposit = $params['api_key_deposit'];
            if(isset($params['api_key_transfer'])) $this->api_key_transfer = $params['api_key_transfer'];
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
        $data =  [
            'mch_id' => $this->mch_id,
            'ptype' => 3,
            'order_sn' => $this->deposit_order->ordernumber,
            'money' => $this->deposit_order->pay_amount,
            'goods_desc' => "",
            "client_ip" => "47.57.70.202",
            'format' => 'url',
            'notify_url' => route('cashier.deposit.callback'),
            'time' => time()
        ];
        $data['sign'] = $this->sign($data,1);
        bob_newlog($this->pre_name."充值data",$data,$this->filename);
        $response = $this->fetchData("?c=Pay",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 1){
                $this->createDepositOrderLogService->excute($this->deposit_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_pay_url'] = $response['data']['qrcode'];
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
        $data =  [
            'mch_id' => $this->mch_id,
            'ptype' => 1,
            'order_sn' => $this->transfer_order->ordernumber,
            'money' => $this->transfer_order->amount,
            'bankname' => $this->getBankName(),
            "accountname" => $this->transfer_order->holder_name,
            'cardnumber' => $this->transfer_order->card_no,
            'format' => 'json',
            'notify_url' => route('cashier.transfer.callback'),
            'time' => time()
        ];
        $data['sign'] = $this->sign($data,2);
        bob_newlog($this->pre_name."代付data",$data,$this->filename);
        $response = $this->fetchData("?c=Df",$data);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 1){
                $this->createTransferOrderLogService->excute($this->transfer_order->id,"返回成功参数",$response,"debug");
                $this->data['channel_id'] = $this->channel_id;
                $this->data['channel_account_id'] = intval(optional($this->channel_account)->offsetGet('id'));
                $this->data['channel_ordernumber'] = $response['data']['order_sn'];
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
            'mch_id' => $this->mch_id,
            'out_order_sn' => $order_id,
            'time' => time()
        ];
        $data['sign'] = $this->sign($data);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("?c=Pay&a=query",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 1){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 9){
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
            'mch_id' => $this->mch_id,
            'out_order_sn' => $order_id,
            'time' => time()
        ];
        $data['sign'] = $this->sign($data,2);
        bob_newlog($this->pre_name."查询",$data,$this->filename);
        $response = $this->fetchData("?c=Df&a=query",$data);
        bob_newlog($this->pre_name."查询，第三方返回参数",[$response],$this->filename);
        if($response->successful()){
            if(isset($response['code']) && $response['code'] == 1){
                if(isset($response['data']) && isset($response['data']['status'])){
                    if($response['data']['status'] == 9){
                        return $response['data'];
                    }
                }
            }
        }
        return;
    }

    private function fetchData($url,$data = [])
    {
        return Http::withHeaders(['Content-Type'=>"application/json"])->asForm()->post($this->pre_url.$url,$data);
    }

    public function checkSign($data,$sign,$api_type = 1)
    {
        $self = $this->sign($data,$api_type);
        if($self == $sign) return true;
        bob_newlog("签名错误",['sign'=>$sign,'self' => $self],$this->filename);
        return;
    }


    private function sign($arr,$api_type = 1)
    {
        $api_key = $api_type == 1 ? $this->api_key_deposit : $this->api_key_transfer;
        ksort($arr);
        $md5str = "";
        foreach ($arr as $key => $val) {
            $md5str = $md5str . $key . "=" . $val . "&";
        }
        bob_newlog("签名字符串",['str'=>$md5str . "key=" . $api_key,'sign'=>md5($md5str."key=".$api_key)],$this->filename);
        return md5($md5str."key=".$api_key);
    }


    private function getBankName(){
        $bankList =  [
            [
                'id' => 1,
                'name' => "中国工商银行",
                'sid' => 65
            ],
            [
                'id' => 2,
                'name' => "中国农业银行",
                'sid' => 27
            ],
            [
                'id' => 3,
                'name' => "中国银行",
                'sid' => 11
            ],
            [
                'id' => 4,
                'name' => "中国建设银行",
                'sid' => 82
            ],
            [
                'id' => 5,
                'name' => "交通银行",
                'sid' => 19
            ],
            [
                'id' => 6,
                'name' => "中信银行",
                'sid' => 9
            ],
            [
                'id' => 7,
                'name' => "中国光大银行",
                'sid' => 21
            ],
            [
                'id' => 8,
                'name' => "长沙银行",
                'sid' => 21
            ],
            [
                'id' => 9,
                'name' => "中国民生银行",
                'sid' => 109
            ],
            [
                'id' => 10,
                'name' => "广发银行",
                'sid' => 75
            ],
            [
                'id' => 11,
                'name' => "深圳发展银行",
                'sid' => -1
            ],
            [
                'id' => 12,
                'name' => "招商银行",
                'sid' => 92
            ],
            [
                'id' => 13,
                'name' => "兴业银行",
                'sid' => 23
            ],
            [
                'id' => 14,
                'name' => "琼海大众村镇银行",
                'sid' => -2
            ],
            [
                'id' => 15,
                'name' => "威海商业银行",
                'sid' => -3
            ],
            [
                'id' => 16,
                'name' => "华融湘江银行",
                'sid' => -4
            ],
            [
                'id' => 17,
                'name' => "渤海银行",
                'sid' => 147
            ],
            [
                'id' => 18,
                'name' => "中国邮政储蓄银行",
                'sid' => 189
            ],
            [
                'id' => 19,
                'name' => "深圳发展银行",
                'sid' => -5
            ],
            [
                'id' => 20,
                'name' => "威海农商银行",
                'sid' => 57
            ],
            [
                'id' => 21,
                'name' => "平安银行",
                'sid' => 67
            ],
            [
                'id' => 22,
                'name' => "农村信用社"
            ],
            [
                'id' => 23,
                'name' => "浦发银行",
                'sid' => 137
            ],
            [
                'id' => 24,
                'name' => "青岛银行",
                'sid' => 210
            ],
            [
                'id' => 25,
                'name' => "海南银行",
                'sid' => 139
            ],
            [
                'id' => 26,
                'name' => "华夏银行",
                'sid' => 32
            ],
            [
                'id' => 27,
                'name' => "龙江银行",
                'sid' => 219
            ],
            [
                'id' => 28,
                'name' => "哈尔滨银行",
                'sid' => 45
            ],
        ];

        $result = collect($bankList)->where('sid',$this->transfer_order->bank_id)->first();
        return optional($result)->offsetGet('id') ?: 45;
    }
}
