<?php
namespace Vendor\WxpayPub;
/**
* 	配置账号信息
*/

class WxPayConfPub {
	//=======【基本信息设置】=====================================
	//微信公众号身份的唯一标识。审核通过后，在微信发送的邮件中查看
	static $APPID = '';
	//JSAPI接口中获取openid，审核后在公众平台开启开发模式后可查看
	static $APPSECRET = '';
	//受理商ID，身份标识
	static $MCHID = '';
	//商户支付密钥Key。审核通过后，在微信发送的邮件中查看
	static $KEY = '';


	//=======【证书路径设置】=====================================
	//证书路径,注意应该填写绝对路径
	static $SSLCERT_PATH = '';
	static $SSLKEY_PATH = '';

	//=======【异步通知url设置】===================================
	//异步通知url，商户根据实际开发过程设定
	static $NOTIFY_URL = '';

	//=======【curl超时设置】===================================
	//本例程通过curl使用HTTP POST方法，此处可修改其超时时间，默认为30秒
	static $CURL_TIMEOUT = 30;

	public static function init($APPID,$APPSECRET,$MCHID,$KEY,$CURL_TIMEOUT=30) {
		self::$APPID=$APPID;
		self::$APPSECRET=$APPSECRET;
		self::$MCHID=$MCHID;
		self::$KEY=$KEY;
		self::$CURL_TIMEOUT=$CURL_TIMEOUT;
	}
	
	public static function setSSL($SSLCERT,$SSLKEY){
		self::$SSLCERT_PATH=$SSLCERT;
		self::$SSLKEY_PATH=$SSLKEY;
	}
	
	public static function setNotify($url){
		self::$NOTIFY_URL=$url;
	}
	
}
