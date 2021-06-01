<?php
namespace Kpw;

class Snippets{
	private $twig;
	
	function __construct($plugin_directory){
		$loader =  new \Twig\Loader\FilesystemLoader($plugin_directory.'templates/');
		$this->twig = new \Twig\Environment($loader, ['cache' => $plugin_directory.'templates/cache', 'debug' => true]);
		$this->twig->addExtension(new \Twig\Extra\Intl\IntlExtension());
		
	}
	
	public function login($response){
		if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('login.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'links' => Response::$links]);
	}

	public function recover($response){
		if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('recover.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'links' => Response::$links]);
	}
	
	public function recoverF($response){
		if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('recoverF.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'links' => Response::$links]);
	}
	
	public function signup($response){
		if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('signup.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'links' => Response::$links]);
	}
	
	public function message($response){
		return $this->twig->render('message.html', ['response'=>['message' => $response->message]]);
	}
	
	public function subscribe($response, $paynow_logo, $email, $monthly_fee, $annual_fee){
		if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('subscribe.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'annual_fee'=>$annual_fee, 'monthly_fee'=>$monthly_fee, 'links' => Response::$links, 'email'=>$email, 'paynow_logo' =>$paynow_logo]);
	}
	
	public function prompt_1($expiration){
		return $this->twig->render('prompt-1.html', ['expiration' => $expiration]);
	}
	
	public function prompt_2($monthly_fee, $annual_fee){
		return $this->twig->render('prompt-2.html', ['links'=> Response::$links, 'price_month'=>$monthly_fee, 'price_year'=>$annual_fee]);
	}
	
	public function prompt_3($monthly_fee, $annual_fee){
		return $this->twig->render('prompt-3.html', ['links'=> Response::$links, 'price_month'=>$monthly_fee, 'price_year'=>$annual_fee]);
	}
}
?>