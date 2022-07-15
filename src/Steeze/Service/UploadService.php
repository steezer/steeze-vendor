<?php
namespace Vendor\Steeze\Service;

use Exception;

class UploadService
{

    const STATUS_NO_CONFIG = 10100;
    const STATUS_NO_SERVICE = 10101;
    const STATUS_NOT_ALLOW_EXT = 10201;
    const STATUS_OVER_MAX_SIZE = 10202;
    
    // 存储文件前缀格式
    const STORE_NAME_PREFIX='Y/m/d';

    // 上传服务类型，默认上传到本地服务器
    private $type = null;

    private $service = null;
    private $config = null;

    // 上传文件选项
    private $options = [
        'allow_ext' => ['jpg', 'jpeg', 'png', 'gif', 'bmp'], //允许上传的文件扩展名
        'max_size' => 4096, //最大允许上传的文件大小(单位：kb)
        'is_thumb' => false, //是否进行缩放处理
    ];

    // 图片处理选项
    private $thumbOptions = [
        'width' => 800, //最大宽度
        'height' => 800, //最大高度
        'cutType' => 0, //剪裁类型
        'force' => false, //是否强制缩放
    ];

    // 文件上传前的处理器
    private $beforeHandle = null;

    // 文件上传后的处理器
    private $afterHandle = null;

    // 是否调试模拟上传（模拟上传不真实上传文件）
    private $debug = false;

    // 上传错误代码
    private $errors = null;


    public function __construct()
    {
        $type = C('cloud_storage_type', 'local');
        $config = C('upload.' . $type);
        if (empty($config) || ($type != 'local' && empty($config['class']))) {
            throw new Exception('upload service ' . $type . ' no config', self::STATUS_NO_CONFIG);
        } else {
            try {
                $this->service = $type != 'local' ? make($config['class'], $config) : null;
                $this->config = $config;
                $this->type = $type;
                $this->errors = array(
                    UPLOAD_ERR_INI_SIZE => L('File size exceeded limit for upload, limit: {0}', ini_get('upload_max_filesize')),
                    UPLOAD_ERR_FORM_SIZE => L('File size exceeded limit for upload'),
                    UPLOAD_ERR_PARTIAL => L('Only part of the file was uploaded'),
                    UPLOAD_ERR_NO_FILE => L('No files were uploaded'),
                    UPLOAD_ERR_NO_TMP_DIR => L('Temporary folder not found'),
                    UPLOAD_ERR_CANT_WRITE => L('File write failed'),
                );
            } catch (Exception $e) {
                throw new Exception('upload service ' . $type . ' no exists', self::STATUS_NO_SERVICE);
            }
        }
    }

    /**
     * 设置上传配置
     * @param string|array $field 字段名称或配置数组
     * @param mixed $name 配置值
     */
    public function setConfig($field, $value = null)
    {
        if (is_array($field)) {
            $this->config = array_merge($this->config, $field);
        } else {
            $this->config[$field] = $value;
        }
    }

    /**
     * 设置为调试状态（调试模式下不真实上传文件）
     * @param boolean $debug 是否为调试
     */
    public function setDebug($debug = true)
    {
        $this->debug = $debug;
    }


    /**
     * 文件上传
     * @param  array|string $data 文件信息
     * @return false|array 成功返回上传信息
     */
    public function upload($data)
    {
        if (is_array($data)) {
            if (
                isset($data['tmp_name']) &&
                is_uploaded_file($data['tmp_name'])
            ) {
                return $this->file($data);
            } else {
                $success = true;
                $result = [];
                foreach ($data as $key => &$file) {
                    $result[$key] = $this->file($file);
                    if (!$result[$key]) {
                        $success = false;
                    }
                }
                return $success ? $result : false;
            }
        } else if (is_string($data)) {
            if (strpos($data, 'data:image/') === 0) {
                //Base64图片上传
                return $this->base64($data);
            } else if (is_file($data)) {
                //从本地磁盘上传
                return $this->file($data);
            }
        }
    }

    /**
     * 普通文件上传
     * @param array|string $file 文件信息数组
     * @return bool|array
     */
    public function file($file)
    {
        $isLocalFile = !is_array($file);
        $size = $isLocalFile ? filesize($file) : $file['size'];
        $name = basename($isLocalFile ? $file : $file['name']);
        if($isLocalFile){
            $ext = fileext($name);
        }else{
            $ext = fileext($name, '_');
            if($ext=='_'){
                $ext=explode('/', $file['type'])[1];
            }
        }
        // 文件类型检查
        if (!$this->check($ext, 'ext')) {
            throw new Exception(L('File extension not allowed for upload'), self::STATUS_NOT_ALLOW_EXT);

            // 异常文件检查
        } else if ($isLocalFile && !is_file($file)) {
            throw new Exception(L('File involid for upload'));
        } else if (!$isLocalFile && !is_uploaded_file($file['tmp_name'])) {
            $massage = isset($this->errors[$file['error']]) ?
                $this->errors[$file['error']] : L('File involid for upload');
            throw new Exception($massage);

            // 文件大小检查
        } elseif (!$this->check($size, 'size')) {
            throw new Exception(
                L('File size exceeded limit for upload, limit: {0}', sizeformat($this->options['max_size'] * 1024)),
                self::STATUS_OVER_MAX_SIZE
            );

            // 文件上传
        } else {
            $path = self::getName($ext);
            $tmpFilePath = tempnam(CACHE_PATH, 'upload_tmp_');

            // 将文件移动到临时文件区域
            if (!$this->debug) {
                $dirname = dirname($tmpFilePath);
                !is_dir($dirname) && mkdir($dirname, 0777, true);
                if (
                    !$isLocalFile ? !move_uploaded_file($file['tmp_name'], $tmpFilePath) :
                    !copy($file, $tmpFilePath)
                ) {
                    throw new Exception(L('File upload failed'));
                }
            }

            // 基础文件信息
            $info['path'] = $path;
            $info['name'] = $name;
            $info['ext'] = $ext;
            $info['size'] = $size;

            // 处理上传
            return $this->doUpload($info, $tmpFilePath);
        }

        return false;
    }

    /**
     * Base64文件上传
     * @param string $data
     * @return bool|array
     */
    public function base64(&$data)
    {
        $ext = strtolower(substr($data, 11, strpos($data, ',') - 18));
        //文件类型检查
        if (!$this->check($ext, 'ext')) {
            throw new Exception(L('File extension not allowed for upload'), self::STATUS_NOT_ALLOW_EXT);
        } else {
            $data = base64_decode(substr($data, strpos($data, ',') + 1));
            $size = strlen($data);

            // 异常文件检查
            if (!$size) {
                throw new Exception(L('File involid for upload'));

                //文件大小检查
            } else if (!$this->check($size, 'size')) {
                throw new Exception(
                    L('File size exceeded limit for upload, limit: {0}', sizeformat($this->options['max_size'] * 1024)),
                    self::STATUS_OVER_MAX_SIZE
                );

                // 文件上传  
            } else {
                $path = self::getName($ext);
                $tmpFilePath = tempnam(CACHE_PATH, 'upload_tmp_');

                // 将文件移动到临时文件区域
                if (!$this->debug) {
                    if (file_put_contents($tmpFilePath, $data) === false) {
                        throw new Exception(L('File upload failed'));
                    }
                }

                // 基础文件信息
                $info['path'] = $path;
                $info['name'] = basename($path);
                $info['ext'] = $ext;
                $info['size'] = $size;

                // 处理上传
                return $this->doUpload($info, $tmpFilePath);
            }
        }

        return false;
    }

    /**
     * 设置允许上传的文件扩展名
     *
     * @param array $ext 类型
     */
    public function setAllowExts($exts)
    {
        $this->options['allow_ext'] = array_map(function ($ext) {
            return strtolower(trim($ext));
        }, is_string($exts) ? explode(',', $exts) : $exts);
    }

    /**
     * 设置允许上传的文件大小
     * @param int $size 大小（单位：kb）
     */
    public function setMaxSize($size)
    {
        $this->options['max_size'] = intval($size);
    }

    /**
     * 设置压缩选项
     *
     * @param bool $isThumb 是否压缩，默认为true
     * @param array $config 演示选项：默认{width: 800,height:800,cutType:0}
     * @return void
     */
    public function setThumb($isThumb = true, $config = [])
    {
        $this->options['is_thumb'] = $isThumb;
        $this->thumbOptions = array_merge($this->thumbOptions, $config);
    }


    /**
     * 设置上传文件前的处理器
     * @param callable $handle 处理器
     */
    public function setBeforeHandle($handle)
    {
        $this->beforeHandle = $handle;
    }

    /**
     * 设置上传文件后的处理器
     * @param callable $handle 处理器
     */
    public function setAfterHandle($handle)
    {
        $this->afterHandle = $handle;
    }

    /**
     * 生成一个上传的文件名称
     * @param string $ext 扩展名
     * @return string
     */
    static function getName($ext)
    {
        $prefix = trim(self::STORE_NAME_PREFIX, '/');
        list($m, $s)=explode(' ',microtime());
        $times=1000*1000;
        $base=36;
        $m=intval(floatval($m)*$times);
        $s=intval($s);
        $rand=rand(1, $base*$base);
        $fixed=str_pad(base_convert($rand, 10, $base),2,'0',STR_PAD_LEFT);
        $basename=base_convert($s*$times+$m, 10, $base);
        $basename=str_pad($basename,11,'0',STR_PAD_RIGHT);
        return date($prefix) .'/'. $basename . $fixed . '.' . $ext;
    }
    
    /**
     * 检查是否为图片
     *
     * @param string $ext 扩展名
     * @return boolean
     */
    static function isImage($ext){
        $extname=strtolower($ext);
        return in_array($extname, ['jpg', 'jpeg', 'bmp', 'png', 'gif']);
    }
    
    /**
     * 获取URL地址
     *
     * @param string $path 相对路径
     * @param string $storeType 存储类型
     * @return string
     */
    static function getUrl($path, $storeType='local')
    {
        $config = C('upload.' . $storeType);
        if(!empty($config)){
            $domain = (!empty($config['domain']) ? rtrim($config['domain'], '/') : '');
            $subpath = !empty($config['subpath']) ? trim($config['subpath'], '/') . '/' : '';
            return $domain .'/'. ltrim($subpath . $path, '/');
        }
        return '/ufs/'.$path;
    }
    
    /**
     * 获取附件存储位置
     *
     * @param string $path 相对路径
     * @param string $storeType 存储类型
     * @return string
     */
    static function getStorePath($path, $storeType='local')
    {
        $config = C('upload.' . $storeType);
        if(isset($config['save_path'])){
            return $config['save_path'].$path;
        }else if(isset($config['localBackup']) && $config['localBackup']){
            return $config['backupPath'].$path;
        }else{
            return self::getUrl($path, $storeType);
        }
    }

    /**
     * 检查上传的文件信息
     * @param string $value 待检查的值
     * @param string $type 检查的类型
     * @return bool
     */
    private function check($value, $type)
    {
        switch ($type) {
            case 'ext':
                $ext = strtolower($value);
                return in_array($ext, $this->options['allow_ext']);
            case 'size':
                $size = is_numeric($value) ? intval($value) : filesize($value);
                $maxSize = $this->options['max_size'] * 1024;
                return !$maxSize || $size <= $maxSize;
        }
    }

    /**
     * 调用处理器
     * @param mixed $handle 处理器（可以为包含参数的数组）
     * @param array $params 参数
     * @return mixed 处理器返回的结果
     */
    private function callHandle($handle, ...$params)
    {
        if (is_callable($handle)) {
            return call_user_func_array($handle, $params);
        }
        return null;
    }


    /**
     * 处理上传
     *
     * @param array $info 上传文件信息
     * @param string $tmpFilePath 待上传的临时文件路径
     */
    private function doUpload($info, $tmpFilePath)
    {
        $isImage=self::isImage($info['ext']);
        $isThumb=0;
        // 对图片进行压缩处理
        if (
            !$this->debug && 
            $this->options['is_thumb'] && 
            $isImage
        ) {
            $isThumb=1;
            $maxWidth = intval($this->thumbOptions['width']);
            $maxHeight = intval($this->thumbOptions['height']);
            $cutType = intval($this->thumbOptions['cutType']);
            $isForce = intval($this->thumbOptions['force']);
            $image = make(\Library\Image::class);
            $image->thumbImg($tmpFilePath, $tmpFilePath, $maxWidth, $maxHeight, $cutType, $isForce);
        }
        
        $info['is_image']=$isImage ? 1 : 0;
        $info['is_thumb']=$isThumb ? 1 : 0;
        $info['store_type']=$this->type;
        $info['md5'] = md5_file($tmpFilePath);
        if($isImage){
            $imageInfo = getimagesize($tmpFilePath);
            if($imageInfo){
                $imageTypes=[
                    '','GIF', 'JPG','PNG','SWF','PSD','BMP','TIFF','TIFF',
                    'JPC','JP2', 'JPX','JB2','SWC','IFF','WBMP','XBM'
                ];
                $imageType=$imageInfo[2];
                $info['image_width']=$imageInfo[0];
                $info['image_height']=$imageInfo[1];
                $info['image_type']=isset($imageTypes[$imageType]) ? $imageTypes[$imageType] : '';
                $info['image_bits']=isset($imageInfo['bits']) ? $imageInfo['bits'] : -1;
                $info['image_channels']=isset($imageInfo['channels']) ? $imageInfo['channels'] : -1;
                $info['image_mime']=isset($imageInfo['mime']) ? $imageInfo['mime'] : '';
            }
        }
        
        // 相关路径
        $path = $info['path'];
        $domain = (!empty($this->config['domain']) ? rtrim($this->config['domain'], '/') : '');
        $subpath = !empty($this->config['subpath']) ? trim($this->config['subpath'], '/') . '/' : '';
        $info['url'] = $domain .'/'. ltrim($subpath . $path, '/');

        // 调试模式不进行实际上传
        if ($this->debug) {
            return $info;
        }

        // 如果处理器返回false则停止上传
        $ret = $this->callHandle($this->beforeHandle, $info);
        if ($ret === true) {
            // 开始上传
            $savePath = null;
            if ($this->type != 'local') {
                // 上传到云服务
                $uploadPath=ltrim($subpath . $path, '/');
                $uploader = $this->service->file($this->config['bucket'], $uploadPath);
                $result = $uploader->put($tmpFilePath);
                unset($uploader);
                $isLocalBackup = isset($this->config['localBackup']) &&
                    $this->config['localBackup'] && isset($this->config['backupPath']);
                if ($isLocalBackup) {
                    $savePath = $this->config['backupPath'] . str_replace('/', DS, $path);
                    $dirname = dirname($savePath);
                    !is_dir($dirname) && mkdir($dirname, 0777, true);
                    rename($tmpFilePath, $savePath);
                } else {
                    unlink($tmpFilePath);
                }
            } else {
                // 上传到本地
                $savePath = (!empty($this->config['save_path']) ?
                    rtrim($this->config['save_path'], DS) :
                    ROOT_PATH . 'assets' . DS . 'ufs') . DS . str_replace('/', DS, $path);
                $dirname = dirname($savePath);
                !is_dir($dirname) && mkdir($dirname, 0777, true);
                $result = rename($tmpFilePath, $savePath);
            }

            // 执行上传完成后函数处理
            if (!$result) {
                throw new Exception(L('File upload failed'));
            }
            
            $ret = $this->callHandle($this->afterHandle, $info);
            if ($ret === false) {
                // 如果后续处理失败，则清除上传文件
                $this->type != 'local' && $this->service->delete();
                !empty($savePath) && unlink($savePath);
            }
            // 如果后续处理函数有返回值，则返回此值，否则返回上传文件信息
            return $ret !== null ? $ret : $info;
        } else {
            unlink($tmpFilePath);
        }
        return $ret;
    }
}
