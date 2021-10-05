<?php
namespace Vendor\Steeze\Service;

use Vendor\WxpayPub\WxPayConfPub;
use Vendor\WxpayPub\Helper\NativeLinkPub;
use Vendor\WxpayPub\Helper\NotifyPub;
use Library\Request;
use Library\Response;
use Vendor\WxpayPub\Helper\UnifiedOrderPub;
use Vendor\WxpayPub\Helper\JsApiPub;
use Vendor\WxpayPub\Helper\OrderQueryPub;
use Vendor\Wxpay\Model\WxPayMchPay;
use Vendor\Wxpay\WxPayApi;
use Vendor\Wxpay\WxPayConfig;

/**
 * 微信支付开发服务处理类
 * 
 */
class WxPayService
{
	private $request=null;
	private $response=null;
	
	public function __construct(Request $request,Response $response){
		$this->request=$request;
		$this->response=$response;
	}
	
	/**
	 * 设置公众号配置
	 * 
	 * @param array $config
	 */
	public function setConfig($config){
        $notifyUrl=isset($config['notifyUrl'])?$config['notifyUrl']:'';
		WxPayConfPub::$APPID=$config['appId'];
		WxPayConfPub::$MCHID=$config['mchId'];
		WxPayConfPub::$KEY=$config['mchKey'];
		WxPayConfPub::$SSLCERT_PATH=$config['certPath'];
		WxPayConfPub::$SSLKEY_PATH=$config['keyPath'];
		WxPayConfPub::$NOTIFY_URL=$notifyUrl;
        
        WxPayConfig::$APPID=$config['appId'];
        WxPayConfig::$APPSECRET=$config['appSecret'];
        WxPayConfig::$MCHID=$config['mchId'];
        WxPayConfig::$KEY=$config['mchKey'];
        WxPayConfig::$SSLCERT_PATH=$config['certPath'];
        WxPayConfig::$SSLKEY_PATH=$config['keyPath'];
        WxPayConfig::$NOTIFY_URL=$notifyUrl; 
	}
    
    /**
	 * 获取生成支付配置信息
	 * 
	 * @param array $data 支付信息
     * @param string $scene 支付场景，默认JSAPI，支持JSAPI、NATIVE、APP、MWEB
	 * @return array
     * @example 
     * 支付信息参数$data
     * [
     *  'order_id' => 1, //订单ID
     *  'user_id' => 1, //用户ID
     *  'openid'=> 'oAUSp5UqAFr5XwhNQ4w9V1Ro4tfw', //用户平台OPENID
     *  'total_pay'=> 0.01, //支付金额，单位（元）
     *  'memo'=> '订单支付', //支付备注
     *  'product_id'=> 101, //产品ID，native支付时必须填写
     *  'order_type' => 0, //订单类型（可选），默认为0
     *  'notify_url'=> 'http://www.stwms.cn/wxpay/notify', //通知地址（可选），不设置从全局配置获取
     * ]
     * 支付场景说明：
     *  JSAPI: 公众号或小程序支付
     *  NATIVE: native支付
     *  APP: app支付
     *  MWEB: H5支付
	 */
	public function getPayConfig($data, $scene='JSAPI'){
		$jsApi=new JsApiPub();
		$data['trade_type']=$scene;
		$jsApi->setPrepayId($this->getPrepayId($data));
		return $jsApi->getParameters(false);
	}
    
    /**
     * 企业付款
     *
     * @param string $openid 用户OPENID
     * @param float $amount 付款金额（单位：分）
     * @param string|int $order_id 订单号（只能是字母或者数字，不能包含有其他字符）
     * @param string $desc 付款备注
     * @param string $real_name 真实姓名（可选）
     * @return array
     */
    public function mchPay($openid, $amount, $order_id, $desc, $real_name=''){
		$input = new WxPayMchPay();
        $input->SetOpenid($openid);
        $input->SetAmount($amount);
        $input->SetPartner_trade_no($order_id);
        $input->SetDesc(cut_str($desc, 80));
        !empty($real_name) && $input->SetRe_user_name($real_name);
        return WxPayApi::mch_pay($input);
	}
	
	/**
	 * 生成线下支付二维码连接
	 * @param string $productId 商品ID
	 * @return
	 */
	public function makeScanQRLink($productId){
		$native=new NativeLinkPub();
		$native->setParameter('product_id', $productId);
		return $native->getUrl();
	}
    
    /**
	 * 处理扫描后生成订单信息
     * 
     * @param \Closure $setData 回调函数，传入的数组参数包括product_id字段，根据product_id返回订单信息
     * @example $setData参数示例：
     * doScanPayback(function($config){
     *   return array_merge($config, [
     *      'order_id'=> 1, //订单ID
     *      'user_id' => 1, //用户ID
     *      'total_pay'=> 0.01, //支付金额，单位（元）
     *      'memo'=> '订单支付', //支付备注
     *      'order_type' => 0, //订单类型（可选），默认为0
     *      'notify_url'=> 'http://www.stwms.cn/wxpay/notify', //通知地址（可选），不设置从全局配置获取  
     *  ]);
     * });
     * 
	 */
	public function doScanPayback(\Closure $setData){
		$notify=new NotifyPub();
		$notify->saveData($this->request->rawContent());
		$isSign=$notify->checkSign();
		if($isSign){
            $result=$notify->getData();
            $data=call_user_func($setData, $result);
			$notify->setReturnParameter('return_code','SUCCESS');//返回状态码
 			$notify->setReturnParameter('appid',WxPayConfPub::$APPID);
 			$notify->setReturnParameter('mch_id',WxPayConfPub::$MCHID);
 			$notify->setReturnParameter('nonce_str',$notify->createNoncestr());
 			$notify->setReturnParameter('prepay_id',$this->getPrepayId($data));
 			$notify->setReturnParameter('result_code','SUCCESS');
 			$notify->setReturnParameter('sign',$notify->getSign($notify->returnParameters));
			$this->response->end($notify->returnXml());
		}
	}
	
	/**
	 * 处理微信异步通知
     * 
     * @param \Closure|null $handle 回调函数，处理成功返回true, 失败返回false
     * @return array 成功返回：['code'=>0,'data'=>[支付通知信息]]，失败返回：['code'=>CODE,'message'=>MESSAGE]
     * 错误码返回说明：
     * 0: 支付成功
     * 110: 系统错误
     * 111: 签名失败
     * 112: 第三方支付失败
     * 113: 无效的支付单号
     * 114: 订单已经支付
     * 115: 订单处理失败
	 */
	public function doNotify(\Closure $handle=null){
		$response=&$this->response;
        $notify=new NotifyPub();
        $return=['code'=>0,'message'=>'SUCCESS'];
        //处理微信返回消息
        $wxpayReturn=function($res) use($notify, $response) {
            if($res['code']==0 || $res['code']==114){
                $notify->setReturnParameter('return_code','SUCCESS');//返回码
                $notify->setReturnParameter('return_msg','OK');//返回信息
            }else{
                $notify->setReturnParameter('return_code','FAIL');//返回码
                $notify->setReturnParameter('return_msg',cut_str($res['message'], 30));//错误信息
            }
            $response->write($notify->returnXml());
            return $res;
        };
        
		try{
            $resData=$this->request->rawContent();
            fastlog($resData);
			$notify->saveData($resData);
			if($notify->checkSign()){
                $result=$notify->getData();
                $return=$this->doResult($result, $handle);
			}else{
                $return=['code'=>111,'message'=>'签名失败'];
            }
		}catch(\Exception $e){
            $return['code']=110;
            $return['message']='系统错误:'.$e->getMessage();
		}
        return call_user_func($wxpayReturn, $return);
	}
    
    /**
     * 处理同步通知
     *
     * @param string $tradeSn 内部交易号
     * @param \Closure $handle
     * @return void
     */
    public function doReturnNotify($tradeSn, \Closure $handle=null){
        $queryPub=new OrderQueryPub();
        $queryPub->setParameter('out_trade_no', $tradeSn);
        $result=$queryPub->getResult();
        return $this->doResult($result, $handle);
    }
    
    /**
     * 处理通知结果
     *
     * @param array $result 通知结果
     * @param \Closure $handle 支付成功后调用的处理器
     * @return void
     */
    private function doResult($result, \Closure $handle=null){
        $return=['code'=>0,'message'=>'SUCCESS'];
        if(
            $result['return_code'] == 'SUCCESS' && 
            $result['result_code'] == 'SUCCESS'
        ){
            $trade_sn=$result['out_trade_no'];
            $outer_sn=$result['transaction_id'];
            //查找通知信息
            $notice=M('payment_notice')->where('trade_sn = \''.$trade_sn.'\'')->find();
            if($notice){
                $updateData=[
                        'pay_time'=>time(),
                        'update_time'=>time(),
                        'is_paid'=>1,
                        'outer_sn'=>$outer_sn,
                        'bank_type'=>$result['bank_type'],
                        'trade_type'=>$result['trade_type'],
                    ];
                $where=['id'=>$notice['id'],'is_paid'=>0];
                $result=M('payment_notice')->where($where)->save($updateData);
                $notice=array_merge($notice, $updateData);
                if($result){
                    if(!is_null($handle)){
                        if(call_user_func($handle, $notice)){
                            $return=['code'=>0,'message'=>'支付成功','data'=>$notice];
                        }else{
                            M('payment_notice')->where($where)->save(['pay_time'=>0,'is_paid'=>0,'update_time'=>time()]);
                            $return=['code'=>115,'message'=>'订单处理失败'];
                        }
                    }
                }else{
                    //已经支付过了则通知第三方
                    $return=['code'=>114,'message'=>'订单已经支付','data'=>$notice];
                }
            }else{
                $return=['code'=>113,'message'=>'无效的支付单号'];
            }
        }else{
            $return=['code'=>112,'message'=>'第三方支付失败'];
        }
        return $return;
    }
	
	/**
	 * 获取预付ID（付款时使用）
	 * @param array $data 付款信息
	 * @return string 预付款ID
	 */
	private function getPrepayId($data){
        $memo=isset($data['memo']) ? $data['memo'] : '订单支付';
        $totalPay=doubleval($data['total_pay']) * 100;
        $notifyUrl=isset($data['notify_url']) && !empty($data['notify_url']) ? $data['notify_url'] : WxPayConfPub::$NOTIFY_URL;
		$tradeType=isset($data['trade_type']) ? $data['trade_type'] : 'NATIVE';
        $order=new UnifiedOrderPub();
		$order->setParameter('out_trade_no', $this->getTradeNo($data));
		$order->setParameter('body', $memo);
		$order->setParameter('total_fee', $totalPay);
		$order->setParameter('notify_url', $notifyUrl);
		$order->setParameter('trade_type', $tradeType);
		isset($data['openid']) && $order->setParameter('openid', $data['openid']);
		return $order->getPrepayId();
	}
	
	/**
	 * 获取内部交易订单号
	 * @param array $data 付款信息
	 * @return string 内部交易订单ID
	 */
	
	private function getTradeNo($data){
        $where['order_id']=intval($data['order_id']);
        $where['order_type']=intval($data['order_type']);
        $where['product_id']=intval($data['product_id']);
        $notice=M('payment_notice')->where($where)->find();
        if(!empty($notice)){
            return $notice['trade_sn'];
        }
		do{
			$notice=['trade_sn' => date('YmdHis').rand(1000,9999)];
			$count=M('payment_notice')->where($notice)->count();
		}while($count!=0);
		
		$notice=array_merge($data,$notice);
		$notice['total_pay']=doubleval($data['total_pay']);
		$notice['create_time']=time();
        $notice['update_time']=time();
		$notice['is_paid']=0;
		
		M('payment_notice')->add($notice);
		return $notice['trade_sn'];
	}
	
}
