<?php
namespace Vendor\Wxpay;
/**
* 	配置账号信息
*/

class WxPayConfig {
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
	/**
	 * TODO：设置商户证书路径
	 * 证书路径,注意应该填写绝对路径（仅退款、撤销订单时需要，可登录商户平台下载，
	 * API证书下载地址：https://pay.weixin.qq.com/index.php/account/api_cert，下载之前需要安装商户操作证书）
	 */
	static $SSLCERT_PATH = '';
	static $SSLKEY_PATH = '';
	
	//=======【curl超时设置】===================================
	//本例程通过curl使用HTTP POST方法，此处可修改其超时时间，默认为30秒
	static $CURL_TIMEOUT = 30;	

	//=======【curl代理设置】===================================
	/**
	 * TODO：这里设置代理机器，只有需要代理的时候才设置，不需要代理，请设置为0.0.0.0和0
	 * 本例程通过curl使用HTTP POST方法，此处可修改代理服务器，
	 * 默认CURL_PROXY_HOST=0.0.0.0和CURL_PROXY_PORT=0，此时不开启代理（如有需要才设置）
	 */
	static $CURL_PROXY_HOST = '0.0.0.0';//"10.152.18.220";
	static $CURL_PROXY_PORT = 0;//8080;

	//=======【上报信息配置】===================================
	/**
	 * TODO：接口调用上报等级，默认紧错误上报（注意：上报超时间为【1s】，上报无论成败【永不抛出异常】，
	 * 不会影响接口调用流程），开启上报之后，方便微信监控请求调用的质量，建议至少
	 * 开启错误上报。
	 * 上报等级，0.关闭上报; 1.仅错误出错上报; 2.全量上报
	 * @var int
	 */
	static $REPORT_LEVENL = 1;
	
	//=======【支付成功通知URL】===================================
	static $NOTIFY_URL='';
	
	public static function init($APPID,$APPSECRET,$MCHID,$KEY,$CURL_TIMEOUT=30,$CURL_PROXY_HOST='0.0.0.0',$CURL_PROXY_PORT=0) {
		self::$APPID=$APPID;
		self::$APPSECRET=$APPSECRET;
		self::$MCHID=$MCHID;
		self::$KEY=$KEY;
		self::$CURL_TIMEOUT=$CURL_TIMEOUT;
		self::$CURL_PROXY_HOST=$CURL_PROXY_HOST;
		self::$CURL_PROXY_PORT=$CURL_PROXY_PORT;
	}
	
	public static function setSSL($SSLCERT,$SSLKEY){
		self::$SSLCERT_PATH=$SSLCERT;
		self::$SSLKEY_PATH=$SSLKEY;
	}
}
