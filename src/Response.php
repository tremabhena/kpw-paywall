<?php
namespace Kpw;

class Response {
	const ERROR = 'error';
	const WARNING = 'warning';
	const SUCCESS = 'success';
	const MESSAGE = 'message';
	
	public $message;
	public $status;
	public static $links;
	
	function __construct($message = null, $status = Response::SUCCESS){
		$this->message = $message;
		$this->status = $status;
	}
}
?>