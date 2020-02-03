<?php
namespace Vendor\WxpayPub;
use Exception;

class  SDKRuntimeException extends Exception {
	public function errorMessage()
	{
		return $this->getMessage();
	}

}
