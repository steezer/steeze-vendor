<?php
namespace Vendor\Aliyun;

class SmsService{
    /**
     * AccessKey ID
     *
     * @var string
     */
    private $accessKeyId='';
    
    /**
     * AccessKeySecret
     *
     * @var string
     */
    private $accessKeySecret='';
    
    /**
     * 短信签名
     *
     * @var string
     */
    private $signName='';
    
    /**
     * 返回结果
     *
     * @var string
     */
    private $result=null;
    
    /**
     * 配置参数设置
     *
     * @param array $config 包含accessKeyId、accessKeySecret、signName
     */
    public function setConfig($config){
        $this->accessKeyId=$config['accessKeyId'];
        $this->accessKeySecret=$config['accessKeySecret'];
        $this->signName=$config['signName'];
    }
    
    /**
     * 获取服务器返回结果
     * 
     * @return string
     */
    public function getResult(){
        return $this->result;
    }
    
    /**
     * 获取参数签名
     *
     * @param array $param
     * @return string
     */
    private function getSignature($param){
        ksort($param);
        $query = http_build_query($param);
        $stringToSign = 'GET&%2F&' . urlencode($query);
        return base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret . '&', true));
    }
    
    /**
     * 发送模板消息（阿里云短信接口）
     *
     * @param string $templateCode 短信模板代码
     * @param string $mobile  接收短信的手机号码
     * @param array $param 短信模板变量对应的实际值，数组格式
     * @return bool
     */
    function send($templateCode, $mobile, $param = [])
    {
        $params = [
            'SignName' => $this->signName,
            'Format' => 'JSON',
            'Version' => '2017-05-25',
            'AccessKeyId' => $this->accessKeyId,
            'SignatureVersion' => '1.0',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => uniqid(),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Action' => 'SendSms',
            'TemplateCode' => $templateCode,
            'PhoneNumbers' => $mobile,
            'TemplateParam' => json_encode($param)
        ];

        // 计算签名并把签名结果加入请求参数
        $params['Signature']=$this->getSignature($params);

        // 发送请求
        $url = 'https://dysmsapi.aliyuncs.com?' . http_build_query($params);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $this->result = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($this->result, true);
        if (isset($result['Code']) && $result['Code']=='OK') {
            return true;
        }
        return false;
    }
    
}