<?php
namespace Vendor\Qiniu\Http;

class Response
{
    public $statusCode;
    public $headers;
    public $contentLength;
    public $body;
    public $filesize;

    public function __construct($code, $headers = [], $body = null, $filesize=0)
    {
        $this->statusCode = $code;
        $this->headers = $headers;
        $this->body = $body;
        $this->contentLength = strlen($body);
        $this->filesize=$filesize;
    }

    public function getContent()
    {
        return $this->body;  
    }

    public function __toString()
    {
        return $this->getContent();
    }
}
