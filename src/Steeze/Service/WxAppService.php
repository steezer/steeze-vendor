<?php
namespace Vendor\Steeze\Service;

use Library\Response;
use Library\Request;

/**
 * 微信小程序开发接口
 * 
 */

class WxAppService
{
	private static $cachePath='';
	private $apiConfig=null;
	private $tplConfig=null;

	public function __construct(Request $request,Response $response){
		$this->request=$request;
		$this->response=$response;
	}

	public function setApiConfig($config){
		$this->apiConfig=$config;
        self::$cachePath=CACHE_PATH.trim(str_replace('\\',DS,static::class),DS).DS.$config['AppId'].DS;
	}

	public function setTplConfig($config){
		$this->tplConfig=$config;
	}

	//获取配置项目
	public function getConfig($key){
		return $this->apiConfig[$key];
	}

	//验证是否是合法登陆来源
	public function isAuth($state){
		return $state && $state == $this->getAuth();
	}
	
	//获取授权码
	public function getAuth(){
		return md5(md5($this->apiConfig['AppId']).$this->apiConfig['AppSecret']);
	}


	/**
	 * 通过该接口生成的小程序码，永久有效，数量有限制
	 * 
	 * @param string $path 可以带参数，不能为空，最大长度 128 字节
	 * @param int $width 二维码的宽度
	 */
	public function getWxAppCode($path,$width=430){
		$url='https://api.weixin.qq.com/wxa/getwxacode?access_token='.$this->getAccessToken();
		$param['path']=ltrim($path,'/');
		$param['width']=$width;
		$savePath=self::$cachePath.'QRCode'.DS;
		$filepath=$savePath.'WxAppCode_'.md5(json_encode($param)).'.jpg';
		if(is_file($filepath)){
			return $filepath;
		}
		!is_dir($savePath) && mkdir($savePath,0777,true);
		$data=$this->https_request($url,json_encode($param));
		file_put_contents($filepath,$data);
		return $filepath;
	}

	/**
	 * 通过该接口生成的小程序码，永久有效，数量暂无限制
	 * 
	 * @param string $scene 场景值，最大32个可见字符，只支持数字，大小写英文以及部分特殊字符：!#$&'()*+,/:;=?@-._~
	 * @param string $page 必须是已经发布的小程序存在的页面（否则报错），不能携带参数（参数请放在scene字段里），如果不填写这个字段，默认跳主页面
	 * @param int $width 二维码的宽度
	 */
	public function getWxAppSceneCode($scene,$page='',$width=430){
		$url='https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token='.$this->getAccessToken();
		$param['scene']=$scene;
		$param['page']=ltrim($page,'/');
		$param['width']=$width;
		
		$savePath=self::$cachePath.'QRCode'.DS;
		$filepath=$savePath.'WxAppSceneCode_'.md5(json_encode($param)).'.jpg';
		if(is_file($filepath)){
			return $filepath;
		}
		!is_dir($savePath) && mkdir($savePath,0777,true);
		$data=$this->https_request($url,json_encode($param));
		file_put_contents($filepath,$data);
		return $filepath;
	}

	/**
	 * 通过该接口生成的小程序二维码，永久有效
	 * 
	 * @param string $path 可以带参数，不能为空，最大长度 128 字节
	 * @param int $width 二维码的宽度
	 */
	public function getQRCode($path,$width=430){
		$url='https://api.weixin.qq.com/cgi-bin/wxaapp/createwxaqrcode?access_token='.$this->getAccessToken();
		$param['path']=ltrim($path,'/');
		$param['width']=$width;

		$savePath=self::$cachePath.'QRCode'.DS;
		$filepath=$savePath.'QRCode_'.md5(json_encode($param)).'.jpg';
		if(is_file($filepath)){
			return $filepath;
		}
		!is_dir($savePath) && mkdir($savePath,0777,true);
		$data=$this->https_request($url,json_encode($param));
		file_put_contents($filepath,$data);
		return $filepath;
	}




	////////////////////消息发送相关函数/////////////////////
	//发送模板消息
	public function sendTplMsg($type,$openid,$datas,$url='',$topColor='#000000'){
		$sendDatas=array(
			'touser'=>$openid,
			'template_id'=>$this->tplConfig[$type],
			'url'=>$url,
			'topcolor'=>$topColor
		);
		foreach($datas as $key=> $val){
			if(is_array($val)){
				$datas[$key]['value']=urlencode($val['value']);
			}else{
				$datas[$key]=array(
					'value'=>urlencode($val),
					'color'=>'#000000'
				);
			}
		}
		$sendDatas['data']=$datas;
		$sendDatas=urldecode(json_encode($sendDatas));

		$req_url='https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$this->getAccessToken();
		$content_length=strlen($sendDatas);
		$options=array(
			'http'=>array(
				'method'=>'POST',
				'header'=>"Content-type: application/x-www-form-urlencoded\r\nContent-length: $content_length\r\n",
				'content'=>$sendDatas
			)
		);
		$result=file_get_contents($req_url,false,stream_context_create($options));
		return json_decode($result,true);
	}
	
	//长地址转短地址
	public function shortUrl($long_url){
		$url='https://api.weixin.qq.com/cgi-bin/shorturl?access_token='.$this->getAccessToken();
		$sendDatas=array(
				'action'=>'long2short',
				'long_url'=>$long_url,
		);
		$result=json_decode($this->https_request($url,$sendDatas),true);
		return is_array($result)&&!$result['errcode']&&$result['short_url'] ? $result['short_url'] : '';
	}

	/////////////////////基础支持函数////////////////////
	///////////////////////////////////////////////////
	//初次接入进行验证，在微信与网站接口函数处调用
	public function checkSignature(){
		if(isset($_GET['signature']) && isset($_GET['timestamp']) && isset($_GET['nonce']) && isset($_GET['echostr'])){
			$signature=$_GET['signature'];
			$timestamp=$_GET['timestamp'];
			$nonce=$_GET['nonce'];
			$tmpArr=array($this->getConfig('Token'),$timestamp,$nonce);
			sort($tmpArr,SORT_STRING);
			$tmpStr=implode($tmpArr);
			$tmpStr=sha1($tmpStr);
			if($tmpStr == $signature){
				echo $_GET['echostr'];
			}
			exit(0);
		}
	}

	//获取来自微信端的数据
	public function getData(){
		$content=$GLOBALS['HTTP_RAW_POST_DATA'];
		if(!$content){
			$content = file_get_contents('php://input');
		}
		!empty($content) || die('sorry,this is the interface for weixin');
		$data=new \SimpleXMLElement($content);
		$datas=array();
		foreach($data as $key=> $value){
			$datas[$key]=strval($value);
		}
		return $datas;
	}

	//信息提交，使用http
	public function https_request($url,$data=null,$header=null){
		$curl=curl_init();
		curl_setopt($curl,CURLOPT_URL,$url);
		curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,FALSE);
		if(!empty($header)){
			if(!is_array($header)){
				$headers[]=$header;
			}else{
				$headers=$header;
			}
			curl_setopt($curl,CURLOPT_HTTPHEADER,$headers);
		}
		curl_setopt($curl,CURLOPT_USERAGENT,'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)');
		if(!empty($data)){
			curl_setopt($curl,CURLOPT_POST,1);
			curl_setopt($curl,CURLOPT_POSTFIELDS,$data);
		}
		curl_setopt($curl,CURLOPT_RETURNTRANSFER,1);
		$output=curl_exec($curl);
		curl_close($curl);
		return $output;
	}

	//通用方式获取AccessToken
	public function getAccessToken(){
		$surl='https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={APPID}&secret={APPSECRET}';
		if(!is_dir(self::$cachePath)){
			@mkdir(self::$cachePath,0777,true);
		}
		$filename=self::$cachePath.md5(serialize($this->apiConfig));
		$arr='';
		if(!file_exists($filename) ||
				(
				($arr=explode("\t",file_get_contents($filename))) &&
				(time() - filemtime($filename)) >= intval($arr[1])
				)
		){
			$surl=str_replace('{APPID}',$this->apiConfig['AppId'],str_replace('{APPSECRET}',$this->apiConfig['AppSecret'],$surl));
			$arrs=json_decode($this->https_request($surl),true);
			if(is_array($arrs) && $arrs['access_token']){
				file_put_contents($filename,$arrs['access_token']."\t".$arrs['expires_in']."\t".time());
				return $arrs['access_token'];
			}
		}
		if(!$arr){
			$arr=explode("\t",file_get_contents($filename));
		}
		return $arr[0];
	}
	
	//通过code获取Session,返回:{"openid":"OPENID","session_key":"SESSIONKEY"}
	public function getSessionByCode($code){
		$url='https://api.weixin.qq.com/sns/jscode2session?appid='.$this->apiConfig['AppId'].'&secret='.$this->apiConfig['AppSecret'].'&js_code='.$code.'&grant_type=authorization_code';
		$arrs=json_decode($this->https_request($url),true);
		return is_array($arrs) && $arrs['openid'] ? $arrs : false;
	}

}