<?php

namespace Richard\Payment\Channel;

use App\Models\DepositOrder;
use App\Models\TransferOrder;
use App\Services\Cache\ChannelAccount\CacheLastChannelAccountInfoService;
use App\Services\DepositOrderLog\CreateDepositOrderLogService;
use App\Services\TransferOrderLog\CreateTransferOrderLogService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

abstract class BasePayment
{

    protected $transfer_order;
    protected $deposit_order_id = 0;
    protected $transfer_order_id = 0;
    protected $channel_account;
    protected $deposit_order;
    protected $data = [];
    protected $filename = "payment";
    protected $createDepositOrderLogService;
    protected $createTransferOrderLogService;
    protected $pre_name = "第三方支付，";
    protected $channel_id = 0;
    protected $pre_url;

    function __construct($filename = "")
    {
        if (!empty($filename)) $this->filename = $filename;
        $this->createDepositOrderLogService = App::makeWith(CreateDepositOrderLogService::class,['filename'=>$this->filename]);
        $this->createTransferOrderLogService = App::makeWith(CreateTransferOrderLogService::class,['filename'=>$this->filename]);
    }

    abstract function deposit($deposit_order_id);

    abstract function transfer($transfer_order_id);

    abstract function queryDepositStatus($order_id);

    abstract function queryTransferStatus($order_id);

    protected function getChannelAccount($amount = 0,$type = 1,$channel_id = 0)
    {
        $channel_account_item = App::make(CacheLastChannelAccountInfoService::class)->excute($channel_id);
        if(!empty($channel_account_item)){
            if($type == 1){
                if($channel_account_item['pay_min_amount'] > 0 && $amount < $channel_account_item['pay_min_amount']){
                    $this->createDepositOrderLogService->excute($this->deposit_order->id,"充值金额小于渠道单笔下限",['充值金额'=>$amount,'渠道单笔下限'=>$channel_account_item['pay_min_amount']],"debug");
                    throw new \Exception("充值金额小于渠道单笔下限");
                }
                if($channel_account_item['pay_max_amount'] > 0 && $amount > $channel_account_item['pay_max_amount']){
                    $this->createDepositOrderLogService->excute($this->deposit_order->id,"充值金额大于渠道单笔上限",['充值金额'=>$amount,'渠道单笔上限'=>$channel_account_item['pay_max_amount']],"debug");
                    throw new \Exception("充值金额大于渠道单笔上限");
                }
                if($channel_account_item['pay_total_amount'] > 0){
                    $today_pay_total_amount = DepositOrder::whereDate('created_at',date('Y-m-d'))->where('channel_account_id',$channel_account_item['id'])->where(function ($q){
                        $q->where('status',1)->orWhere('status',3)->orWhere('status',5)->orWhere('status',7);
                    })->sum('pay_amount');
                    if($today_pay_total_amount > $channel_account_item['pay_total_amount']){
                        $this->createDepositOrderLogService->excute($this->deposit_order->id,"充值金额大于渠道日总限额",['充值金额'=>$amount,'渠道日总限额'=>$channel_account_item['pay_total_amount']],"debug");
                        throw new \Exception("充值金额大于渠道日总限额");
                    }
                }
                $this->channel_account = $channel_account_item;
            }

            if($type == 2){
                if($channel_account_item['collection_min_amount'] > 0 && $amount < $channel_account_item['collection_min_amount']){
                    $this->createTransferOrderLogService->excute($this->transfer_order->id,"代付金额小于渠道单笔下限",['代付金额'=>$amount,'渠道单笔下限'=>$channel_account_item['collection_min_amount']],"debug");
                    throw new \Exception("代付金额小于渠道单笔下限");
                }
                if($channel_account_item['pay_max_amount'] > 0 && $amount > $channel_account_item['pay_max_amount']){
                    $this->createTransferOrderLogService->excute($this->transfer_order->id,"代付金额大于渠道单笔上限",['代付金额'=>$amount,'渠道单笔上限'=>$channel_account_item['collection_max_amount']],"debug");
                    throw new \Exception("代付金额大于渠道单笔上限");
                }
                if($channel_account_item['pay_total_amount'] > 0){
                    $today_pay_total_amount = TransferOrder::whereDate('created_at',date('Y-m-d'))->where('channel_account_id',$channel_account_item['id'])->where('status','<>',5)->sum('pay_amount');
                    if($today_pay_total_amount > $channel_account_item['collection_total_amount']){
                        $this->createTransferOrderLogService->excute($this->transfer_order->id,"代付金额大于渠道日总限额",['代付金额'=>$amount,'渠道日总限额'=>$channel_account_item['collection_total_amount']],"debug");
                        throw new \Exception("代付金额大于渠道日总限额");
                    }
                }
                $this->channel_account = $channel_account_item;
            }

        }
    }

    protected function getDepositOrderInfo()
    {
        $result = DepositOrder::where('id',$this->deposit_order_id)->where('status',1)->first();
        if($result){
            $this->deposit_order = $result;
            return;
        }
        bob_newlog($this->pre_name."未查询到充值订单",['deposit_order_id'=>$this->deposit_order_id],$this->filename);
        throw new \Exception("未查询到充值订单");
    }

    protected function getTransferOrderInfo()
    {
        $result = TransferOrder::where('id',$this->transfer_order_id)->with('bank')->first();
        if($result){
            $this->transfer_order = $result;
            return;
        }
        bob_newlog($this->pre_name."未查询到代付订单",['transfer_order_id'=>$this->transfer_order_id],$this->filename);
        throw new \Exception("未查询到代付订单");
    }


    protected function fetchData($url,$data = [])
    {
        return Http::withHeaders(['Content-Type'=>"application/json"])->post($this->pre_url.$url,$data);
    }

    protected function checkSign($data,$sign){
        $self = $this->sign($data);
        if($self == $sign) return true;
        bob_newlog("签名错误",['sign'=>$sign,'self' => $self],$this->filename);
        return;
    }

    protected function sign($arr)
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
