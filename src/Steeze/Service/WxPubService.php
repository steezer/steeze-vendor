<?php
namespace Vendor\Steeze\Service;

use Library\Response;
use Library\Request;

/**
 * 微信公众号开发接口
 * 
 */

class WxPubService
{
	private $cachePath='';
	private $apiConfig=null;
	private $tplConfig=null;
	private $request=null;
	private $response=null;
	
	public function __construct(Request $request,Response $response){
		$this->request=$request;
		$this->response=$response;
	}
	
	/**
	 * 设置API配置
	 *
	 * @param array $config API配置
	 */
	public function setApiConfig($config){
		$this->apiConfig=$config;
		$this->cachePath=CACHE_PATH.trim(str_replace('\\',DS,static::class),DS).DS.$config['pubId'].DS;
	}
	
	/**
	 * 设置模板消息配置
	 *
	 * @param array $config 消息配置
	 */
	public function setTplConfig($config){
		$this->tplConfig=$config;
	}
	
	/**
	 * 获取配置信息
	 * @param string|null $key 键名称
	 * @return array|string
	 */
	public function getConfig($key=null){
		return is_null($key) ? $this->apiConfig : $this->apiConfig[$key];
	}
	

	///////////////////////////////////////////////////
	////////////////////消息处理相关函数//////////////////
	
	/**
	 * 公众号消息分发
	 * */
	public function dispatchMessage(\Closure $callback){
		$result=$this->checkSignature();
		if(!is_null($result)){
			$this->response->end($result);
		}else{
			$data=$this->getData();
			if(is_array($data) && isset($data['FromUserName'])){
				$this->response->header('Content-Type','text/html; charset=utf-8');
				$this->response->header('Cache-control','private');
				
				//将消息或事件类型转换为对应方法名称
				$method=parse_name(
							$data['MsgType']!='event' ?
							($data['MsgType']!='device_event' ? 'reply_'.$data['MsgType'] : 'on_device_'.$data['Event']) :
							'on_'.$data['Event'],
						1);
				$callback($method,$data['FromUserName'],$data);
			}
		}
	}
	
	/**
	 * 生成回复消息
	 * 
	 * @param string $openid 目标用户OPENID
	 * @param string|array $data 消息内容
	 * @param string $type 消息类型，默认为text
	 * @return string
	 * 支持的消息类型说明：
	 * 1、text（文本消息）：$data参数为消息内容
	 * 2、image（图片消息）：$data参数为图片的MediaId或文件路径
	 * 3、voice（语音消息）：$data参数为语音的MediaId或文件路径
	 * 4、video（视频消息）：
	 * 	如果$data为字符串，则为视频的MediaId或文件路径；
	 * 	如果为数组，格式为：{'MediaId'=>'MediaId或文件路径','Title'=>'标题','Description'=>'描述'}
	 * 5、music（音乐消息）：
	 * 	$data参数为数组，格式为：
	 * 	{
	 * 		'Title'=>'标题',
	 * 		'Description'=>'描述',
	 * 		'MusicURL'=>'音乐链接',
	 * 		'HQMusicUrl'=>'高质量音乐链接',
	 * 		'ThumbMediaId'=>'缩略图的媒体id'
	 * }
	 * 6、news（图文消息）：
	 * 	$data参数为数组（最多8个子元素），格式为：
	 *  [
	 *  		['Title':'标题1','Description':'描述1','PicUrl':'图片链接1','Url':'跳转链接1'],
	 *  		['Title':'标题2','Description':'描述2','PicUrl':'图片链接2','Url':'跳转链接2'],
	 *  ]
	 */
	public function makeMessage($openid,$data,$type='text',$FromUserName=null){
		$info['ToUserName']=$openid;
		$info['FromUserName']=(isset($FromUserName) ? $FromUserName : $this->apiConfig['pubId']);
		$info['CreateTime']=time();
		$info['MsgType']=$type;
		switch ($type){
			case 'text':
				$info['Content']=strval($data);
				break;
			case 'image':
			case 'voice':
			case 'video':
			case 'music':
				$key=($type!='music' ? 'MediaId' : 'ThumbMediaId');
				$mediaId=!is_array($data) ? $data : $data[$key];
				$medias=is_array($data) ? $data : [];
				$medias[$key]=is_file($mediaId) ? $this->uploadMedia($mediaId,$type) : $mediaId;
				$info[ucfirst($type)]=$medias;
				break;
			case 'news':
				$info['Articles']=$data;
				break;
			default:
				$info=array_merge($info,(array)$data);
				break;
		}
		return self::createXmlMessage($info);
	}
	
	/**
	 * 主动发送模板消息
	 *
	 * @param string 消息类型
	 * @param string $openid 目标用户OPENID
	 * @param array $datas 消息数据
	 * @param string $url 点击消息后跳转的URL
	 * @param string $titleColor 消息标题颜色
	 * @return array 返回发送结果
	 *
	 */
	public function sendTemplateMessage($type,$openid,$datas,$url='',$titleColor='#000000'){
		$sendDatas=array(
				'touser'=>$openid,
				'template_id'=>$this->tplConfig[$type],
				'url'=>$url,
				'topcolor'=>$titleColor
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
	
	
	///////////////////////////////////////////////////
	///////////////////微信智能硬件相关///////////////////
	
	/**
	 * 主动发送设备消息
	 * 
	 * @param string $content 消息内容
	 * @param string $deviceid 设备ID
	 * @param string $openid OPENID
	 * @return array
	 */
	public function sendDeviceMessage($content,$deviceid,$openid){
		$url='https://api.weixin.qq.com/device/transmsg?access_token='.$this->getAccessToken();
		$data['device_type']=$this->apiConfig['pubId'];
		$data['device_id']=$deviceid;
		$data['open_id']=$openid;
		$data['content']=base64_encode($content);
		$result=$this->https_request($url,json_encode($data));
		return json_decode($result,true);
	}
	
	/**
	 * 回复设备信息，在接受到设备的事件或消息后回复设备
	 * 
	 * @param string $msg 消息
	 * @param array $data 消息
	 * @return string
	 */
	public function replyDeviceMessage($msg,$data){
		$info['DeviceType']=$data['DeviceType'];
		$info['DeviceID']=$data['DeviceID'];
		$info['SessionID']=$data['SessionID'];
		$info['Content']=$msg;
		$this->response->end($this->makeMessage($data['FromUserName'], $info,$data['MsgType'],$data['ToUserName']));
	}
	
	/**
	 * 强制绑定设备
	 * 
	 * @param string $deviceid 设备ID
	 * @param string $openid OPENID
	 * @return true | string 成功返回true，否则返回错误信息字符串
	 */
	public function complBindDevice($deviceid,$openid){
		$url='https://api.weixin.qq.com/device/compel_bind?access_token='.$this->getAccessToken();
		$data['device_id']=$deviceid;
		$data['openid']=$openid;
		$result=json_decode($this->https_request($url,json_encode($data)),true);
		return is_array($result) && isset($result['base_resp']['errcode']) ? 
					($result['base_resp']['errcode']!=-1?true:$result['base_resp']['errmsg']) : 'https error!';
	}
	
	/**
	 * 强制解除设备绑定
	 * 
	 * @param string $deviceid 设备ID
	 * @param string $openid OPENID
	 * @return true | string 成功返回true，否则返回错误信息字符串
	 */
	public function complUnbindDevice($deviceid,$openid){
		$url='https://api.weixin.qq.com/device/compel_unbind?access_token='.$this->getAccessToken();
		$data['device_id']=$deviceid;
		$data['openid']=$openid;
		$result=json_decode($this->https_request($url,json_encode($data)),true);
		return is_array($result) && isset($result['base_resp']['errcode']) ?
					($result['base_resp']['errcode']!=-1 ? true: $result['base_resp']['errmsg']) : 'https error!';
	}
	
	/**
	 * 获取设备授权信息
	 *
	 * @param $product_id
	 * @return array
	 */
	public function deviceAuthorize($product_id=1){
		$url='https://api.weixin.qq.com/device/getqrcode?access_token='.$this->getAccessToken().'&product_id='.$product_id;
		return json_decode($this->https_request($url),true);
	}
	
	
	///////////////////////////////////////////////////
	/////////////////////基础支持函数////////////////////
	
	/**
	 * 自定义登陆来源合法性验证
	 * @param string $state 状态值
	 * @return bool 是否合法
	 */
	public function isAuth($state){
		return $state && $state == md5(md5($this->apiConfig['appId']).$this->apiConfig['appSecret']);
	}
	
	/**
	 * 根据当前公众号唯一openid获取用户消息
	 *
	 * @param string $openid 用户的OPENID
	 * @param string $accessToken 指定的AccessToken，默认null为自动获取
	 * @param bool $isOpen 是否使用开放平台获取，默认为false
	 * @return array 返回用户信息
	 * 用户信息参见：https://mp.weixin.qq.com/wiki?t=resource/res_main&id=mp1421140839
	 */
	public function getUserInfo($openid,$accessToken=null,$isOpen=false){
		$url=!$isOpen ? 'https://api.weixin.qq.com/cgi-bin/user/info' : 'https://api.weixin.qq.com/sns/userinfo';
		$token=empty($accessToken) ? $this->getAccessToken() : $accessToken;
		$url.='?access_token='.$token.'&openid='.$openid.'&lang=zh_CN';
		return json_decode($this->https_request($url),true);
	}
	
	/**
	 * 根据授权code获取用户信息
	 *
	 * @param string $code 扫码登录后获取的code
	 * @return array 返回用户信息
	 */
	public function getUserByCode($code){
		$info=$this->getAccessTokenByCode($code);
		return $this->getUserInfo($info['openid'],$info['access_token'],true);
	}
	
	/**
	 * 根据当前公众平台唯一的openid，获取用户信息
	 * @param array $token Token信息，包含openid
	 * @return array 返回用户信息
	 */
	public function getUserByToken($token){
		return $this->getUserInfo($token['openid'],$token['access_token'],true);
	}
	
	/**
	 * 获取JsApi授权配置信息
	 *
	 * @param array $auths 授权信息数组
	 * @param string $currentUrl 当前页面地址
	 * @return array 配置信息
	 */
	public function getJsApiConfig(array $auths=[],$currentUrl=null){
		if(empty($currentUrl)){
			$request_uri=$this->request->server('request_uri');
			$query_string=$this->request->server('query_string');
			if(!strpos($request_uri, '?') && !empty($query_string)){
				$request_uri.='?'.$query_string;
			}
			$currentUrl=env('SITE_URL').$request_uri;
		}
		$default=['onMenuShareTimeline','onMenuShareAppMessage'];
		$config=array('debug'=>$this->apiConfig['isDebug'],'appId'=>$this->apiConfig['appId'],'timestamp'=>time(),'nonceStr'=>rand(100,999));
		$shal_str='jsapi_ticket='.$this->getJsApiTicket().'&noncestr='.$config['noncestr'].'&timestamp='.$config['timestamp'].'&url='.$currentUrl;
		$config['signature']=sha1($shal_str);
		if(is_array($auths)){
			$config['jsApiList']=array_merge($default,$auths);
		}
		return $config;
	}
	
	/**
	 * 获取扫码关注二维码
	 *
	 * @param string $qrId 二维码ID
	 * @param int $type 二维码类型，1=>整型参数值,0=>字符串参数值
	 * @param int $expire 过期时间
	 * @return array
	 * 返回：{"ticket":"获取的二维码ticket","expire_seconds":60,"url":"二维码图片解析后的地址"}
	 */
	public function getQRCode($qrId,$type=1,$expire=1800){
		$dataArr['action_name']=!$type ? 'QR_SCENE' : ($type == 1 ? 'QR_LIMIT_SCENE' : 'QR_LIMIT_STR_SCENE');
		if(!$type){
			$dataArr['expire_seconds']=$expire;
		}
		$dataArr['action_info']=array('scene'=>array((!$type || $type == 1 ? 'scene_id' : 'scene_str')=>($type == 1 ? intval($qrId < 1 ? 1 : min($qrId,100000)) : $qrId)));
		
		$sendDatas=json_encode($dataArr);
		unset($dataArr);
		$options=array(
				'http'=>array(
						'method'=>'POST',
						'header'=>"Content-type: application/x-www-form-urlencoded\r\nContent-length: ".strlen($sendDatas)."\r\n",
						'content'=>$sendDatas
				)
		);
		$url='https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token='.$this->getAccessToken();
		return json_decode(file_get_contents($url,false,stream_context_create($options)),true);
	}
	
	/**
	 * 上传多媒体文件（本地缓存3天）
	 * 
	 * @param string $filepath 上传文件路径
	 * @param string $type 媒体文件类型，分别有图片（image）、语音（voice）、视频（video）和缩略图（thumb）
	 * @return array 返回信息：{"type":"TYPE","media_id":"MEDIA_ID","created_at":123456789}
	 */
	public function uploadMedia($filepath,$type='image'){
		$key='wxMedia'.md5($filepath);
		if(!($mediaId=S($key))){
			$sendDatas['media']= !class_exists('\CURLFile',false) ? '@'.$filepath : (new \CURLFile($filepath));
			$req_url='http://file.api.weixin.qq.com/cgi-bin/media/upload?access_token='.$this->getAccessToken().'&type='.$type;
			$result=json_decode($this->https_request($req_url,$sendDatas),true);
			$mediaId=is_array($result) && isset($result['media_id']) ? $result['media_id'] : null;
			!is_null($mediaId) && S($key,$mediaId,3*24*60*60); //缓存3天
		}
		return $mediaId;
	}
	
	/**
	 * 下载多媒体
	 * 
	 * @param string $mediaId 媒体ID，由上传后返回的media_id获得
	 * @param string $savepath 保存路径，如果为空，直接返回结果数据，为false返回下载地址
	 * @return number|mixed 返回信息，如果出错返回null
	 */
	public function downloadMedia($mediaId,$savepath=''){
		$req_url='http://file.api.weixin.qq.com/cgi-bin/media/get?access_token='.$this->getAccessToken().'&media_id='.$mediaId;
		return $savepath===false ? $req_url : get_remote_file($req_url,$savepath);
	}
	
	/**
	 * 创建微信公众号底部菜单
	 * 
	 * @param array 菜单数据
	 * @return array 返回结果
	 */
	public function createMenu($data){
		$result=json_decode(file_get_contents('https://api.weixin.qq.com/cgi-bin/menu/delete?access_token='.$this->getAccessToken()),true);
		if($result['errcode']){
			return $result;
		}
		$url='https://api.weixin.qq.com/cgi-bin/menu/create?access_token='.$this->getAccessToken();
		$header='content-type: application/x-www-form-urlencoded; charset=UTF-8';
		return json_decode($this->https_request($url,$data,$header),true);
	}
	
	/**
	 * 长地址转短地址
	 * 
	 * @param string $long_url 长地址
	 * @return string 转换后的短地址
	 */
	public function shortUrl($long_url){
		$url='https://api.weixin.qq.com/cgi-bin/shorturl?access_token='.$this->getAccessToken();
		$sendDatas=array(
				'action'=>'long2short',
				'long_url'=>$long_url,
		);
		$result=json_decode($this->https_request($url,$sendDatas),true);
		return is_array($result)&&!$result['errcode']&&$result['short_url'] ? $result['short_url'] : '';
	}
	
	/**
	 * 获取网页授权登陆URL
	 * 
	 * @param string $redirect_uri 回调地址
	 * @param string $state 状态码
	 * @param string $scope 授权范围
	 * @return string 授权地址
	 */
	public function getAuthUrl($redirect_uri,$scope='snsapi_base'){
		if(strpos($redirect_uri, '://')===false){
			$redirect_uri=env('SITE_PROTOCOL').$this->apiConfig['authDomain'].'/'.ltrim($redirect_uri,'/');
		}
		$params=array(
			'appid'=>$this->apiConfig['appId'],
			'scope'=>$scope,
			'state'=>md5(md5($this->apiConfig['appId']).$this->apiConfig['appSecret']),
			'redirect_uri'=>urlencode($redirect_uri),
		);
		$url='https://open.weixin.qq.com/connect/oauth2/authorize?appid={$appid}&redirect_uri={$redirect_uri}&response_type=code&scope={$scope}&state={$state}#wechat_redirect';
		foreach($params as $k=>$v){
			$url=str_replace('{$'.$k.'}', $v, $url);
		}
		return $url;
	}
	
	/**
	 * 初次接入进行验证，在微信与网站接口函数处调用
	 * @return string|null 如果为验证签名则返回string，否则返回null
	 */
	public function checkSignature(){
		$gets=$this->request->get();
		if(isset($gets['signature']) && isset($gets['timestamp']) && isset($gets['nonce']) && isset($gets['echostr'])){
			$signature=$gets['signature'];
			$timestamp=$gets['timestamp'];
			$nonce=$gets['nonce'];
			$tmpArr=array($this->apiConfig['appToken'],$timestamp,$nonce);
			sort($tmpArr,SORT_STRING);
			$tmpStr=implode($tmpArr);
			$tmpStr=sha1($tmpStr);
			if($tmpStr == $signature){
				return $gets['echostr'];
			}
		}
		return null;
	}

	/**
	 * 获取来自微信端的数据
	 */
	public function getData(){
		$content = $this->request->rawContent();
		if(!empty($content)){
			$data=new \SimpleXMLElement($content);
			$datas=array();
			foreach($data as $key=> $value){
				$datas[$key]=strval($value);
			}
			return $datas;
		}
		return null;
	}

	/**
	 * 通过code获取Token信息，此token包括openid，此token暂未采用缓存机制
	 * 
	 * @param string $code 授权码
	 * @return array
	 * 返回结果：
	 * 	{ "access_token":"ACCESS_TOKEN","expires_in":7200,"refresh_token":"REFRESH_TOKEN","openid":"OPENID","scope":"SCOPE" }
	 */
	public function getAccessTokenByCode($code){
		$url='https://api.weixin.qq.com/sns/oauth2/access_token?appid='.$this->apiConfig['appId'].'&secret='.$this->apiConfig['appSecret'].'&code='.$code.'&grant_type=authorization_code';
		$data=$this->https_request($url);
		return json_decode($data,true);
	}

	/**
	 * 信息提交，使用http
	 * 
	 * @param string $url 请求地址
	 * @param array|null $data 请求数据，如果为数组则为POST请求，默认为GET请求
	 * @param array|null 发送的头部信息
	 * @return string 返回信息
	 */
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

	/**
	 * 获取JsapiTicket，在JS API中使用
	 */
	public function getJsApiTicket(){
		$surl='https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token={ACCESS_TOKEN}&type=jsapi';
		if(!is_dir($this->cachePath)){
			@mkdir($this->cachePath,0777,true);
		}
		$filename=$this->cachePath.md5($surl);
		$arr='';
		if(!file_exists($filename) ||
				(
				($arr=explode("\t",file_get_contents($filename))) &&
				(time() - filemtime($filename)) > intval($arr[1])
				)
		){
			$surl=str_replace('{ACCESS_TOKEN}',$this->getAccessToken(),$surl);
			$arrs=json_decode($this->https_request($surl),true);
			file_put_contents($filename,$arrs['ticket']."\t".$arrs['expires_in']);
			return $arrs['ticket'];
		}
		if(!$arr){
			$arr=explode("\t",file_get_contents($filename));
		}
		return $arr[0];
	}

	/**
	 * 通用方式获取AccessToken
	 */
	public function getAccessToken(){
		$surl='https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={APPID}&secret={APPSECRET}';
		if(!is_dir($this->cachePath)){
			@mkdir($this->cachePath,0777,true);
		}
		$filename=$this->cachePath.md5(serialize($this->apiConfig));
		$arr='';
		if(!file_exists($filename) ||
				(
				($arr=explode("\t",file_get_contents($filename))) &&
				(time() - filemtime($filename)) >= intval($arr[1])
				)
		){
			$surl=str_replace('{APPID}',$this->apiConfig['appId'],str_replace('{APPSECRET}',$this->apiConfig['appSecret'],$surl));
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
	
	/**
	 * 生成XML格式消息
	 * 
	 * @param array $data 消息数据
	 * @param bool $addRoot 是否添加xml根元素
	 * @return string
	 */
	public static function createXmlMessage($data,$addRoot=true){
		$ret='';
		foreach($data as $key=>$val){
			$skey=is_int($key) ? 'item' : $key;
			if(is_array($val)){
				$ret.='<'.$skey.'>'.self::createXmlMessage($val,false).'</'.$skey.'>';
			}else{
				$ret.='<'.$skey.'>'.(is_numeric($val) ? $val : '<![CDATA['.$val.']]>').'</'.$skey.'>';
			}
		}
		return $addRoot ? '<xml>'.$ret.'</xml>' : $ret;
	}

}
