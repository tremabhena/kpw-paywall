<?php
namespace Kpw;

class Snippets{
	private $twig;
	
	function __construct($plugin_directory){
		$loader =  new \Twig\Loader\FilesystemLoader($plugin_directory.'templates/');
		$this->twig = new \Twig\Environment($loader, ['cache' => $plugin_directory.'templates/cache', 'debug' => true]);
		
	}
	
	public function login($response){
		//if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('login.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'links' => Response::$links]);
	}

	public function recover($response){
		//if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('recover.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'links' => Response::$links]);
	}
	
	public function recoverF($response, $reset_email, $reset_nonce){
		//if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('recoverF.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'reset_email' => $reset_email, 'reset_nonce' => $reset_nonce, 'links' => Response::$links]);
	}
	
	public function signup($response){
		//if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('signup.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'links' => Response::$links]);
	}
	
	public function message($response){
		return $this->twig->render('message.html', ['response'=>['message' => $response->message]]);
	}
	
	public function subscribe($response, $subscribe){
		//if($response->status === Response::MESSAGE) return $this->message($response);
		return $this->twig->render('subscribe.html', ['response'=>['message' => $response->message, 'status' => $response->status], 'yearly_price'=>'2000', 'monthly_price'=>'8000', 'links' => Response::$links], 'email'=>$email);
	}
	
	public function popup(){
		return $this->twig->render('popup-1.html', ['username' => 'treasure sibusiso mabhena', 'renew_link' => 'goo gle.com', 'price_month' => '50', 'price_year' => '1000', 'logout_link'=>'wikipedia.org', 'expiration'=> '17 hours']);
	}
}
?>