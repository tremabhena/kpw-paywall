<?php
/*
Plugin Name: Kainet Paywall
*/
//to-do: fix css reset issue and sizing
require_once('vendor/autoload.php');
use Kpw\Snippets;
use Kpw\Response;

//remove this
function kpw_log($message){
	file_put_contents('kpw-log.txt', $message."\r\n", FILE_APPEND);
}
const LOGINSC = 'kpw-login';
const RECOVERYSC = 'kpw-recovery';
const RECOVERYFSC = 'kpw-recoveryf';
const SIGNUPSC = 'kpw-signup';
const SUBSCRIBESC = 'kpw-subscribe';

session_start();
if(!isset($_SESSION['kpw_user_id'])) $_SESSION['kpw_user_id'] = false;
if(!isset($_SESSION['kpw_history'])) $_SESSION['kpw_history'] = false;
if(!isset($_SESSION['kpw_boomerang'])) $_SESSION['kpw_boomerang'] = array();

//$kpw_response = new Response();
$kpw_response = null;
$reset_email = false;
$reset_nonce = false;

//added for the sake of register_activation_hook()
global $wpdb;
global $subscription_table, $paynow_payment_table, $subscriber_table, $password_recovery_table, $auto_auth_table;

$subscription_table = $wpdb->prefix .'kpw_subscription';
$paynow_payment_table = $wpdb->prefix .'kpw_paynow_payment';
$subscriber_table = $wpdb->prefix .'kpw_subscriber';
$password_recovery_table = $wpdb->prefix.'kpw_password_recovery';
$auto_auth_table = $wpdb->prefix.'kpw_automatic_authentication';

kpw_log($_SESSION['kpw_user_id']);
//attempt to log in via cookie
if(!$_SESSION['kpw_user_id']){
	if(isset($_COOKIE['kpw_user_id'], $_COOKIE['kpw_token'])){
		if($user_id = kpw_check_cookie()) $_SESSION['kpw_user_id'] = $user_id;
	}
}

add_action('init', function(){
	if(isset($_POST['kpw_current_form'])){//is it kpw form submission?
		global $kpw_response;
		global $paynow_payment_table;
		
		global $reset_email;
		global $reset_nonce;
		
		//Handle specific form submissions
		switch($_POST['kpw_current_form']){
			case 'kpw_signin':
				$email = $_POST['kpw_email'];
				$password = $_POST['kpw_password'];
				
				if(!isset($email, $password)){
					$kpw_response = new Response('Please enter your user email and password.', Response::WARNING);
					break;
				}
				
				if(is_email($email)){
					if(strlen($password)>=6){
						kpw_logout();
						if(kpw_login($email, $password)){//succesfully signed in
							kpw_log('User signed in');
							if(isset($_SESSION['kpw_boomerang']['login'])){
								kpw_log('boomerang set');
								wp_redirect($_SESSION['kpw_boomerang']['login']);
								unset($_SESSION['kpw_boomerang']['login']);
								exit;
							}
							elseif(!hasSubscription($email)) {
								kpw_log('does not have subscription');
								wp_redirect(kpw_get_link('kpw-subscribe-page'));
								exit;
							}
							elseif(isset($_SESSION['kpw_history'])){
								kpw_log('history is set');
								wp_redirect($_SESSION['kpw_history']);//go to last article page
								//$_SESSION['kpw_history'] = false;
								exit;
							}
							else {
								kpw_log('going to home page');
								wp_redirect(home_url());
								exit;
							}
						}
						else $kpw_response = new Response('Wrong password/username combination. Please try again.', Response::ERROR);
					}
					else $kpw_response = new Response('Password must be at least 6 characters long.', Response::WARNING);			
				}
				else $kpw_response = new Response('Please enter a valid email address.', Response::WARNING);
			break;
			
			case 'kpw_signup':
				$email = sanitize_email($_POST['kpw_email']);
				$password1 = $_POST['kpw_password1'];
				$password2 = $_POST['kpw_password2'];
				
				if(!isset($email, $password1, $password2)){
					$kpw_response = new Response('Please fill in all the required fields.', Response::WARNING);
					break;
				}
				
				if(strlen($password1) >= 6){
					if($password1 === $password2){
						if(!kpw_user_exists($email)){//check that user doesnt exist
							$user = kpw_add_user($email, $password1);
							kpw_log('User does not exist');
							if($user){//succesfully signed up
								kpw_log('User is valid');
								if(isset($_SESSION['kpw_boomerang']['signup'])){
									kpw_log('Boomerang is set');
									wp_redirect($_SESSION['kpw_boomerang']['signup']);
									unset($_SESSION['kpw_boomerang']['signup']);
									exit;
								}
								elseif(!kpw_logged_in()) {
									kpw_log('User is not logged in redirecting');
									wp_redirect(kpw_get_link('kpw-login-page'));
									exit;
								}
								elseif($_SESSION['kpw_history']){
									kpw_log('History is set');
									wp_redirect($_SESSION['kpw_history']);
									//$_SESSION['kpw_history'] = false;
									exit;
								}
								else {
									kpw_log('Settle for home');
									wp_redirect(home_url());
									exit;
								}
							}
							else $kpw_response = new Response('Failed to create user, please try again later.', Response::ERROR);
						}
						else $kpw_response = new Response('User with this email already exists.', Response::WARNING);
					}
					else $kpw_response = new Response('Passwords do not match.', Response::ERROR);
				}
				else $kpw_response = new Response('Password must be at least 6 characters long.', Response::WARNING);
				
			break;
			case 'kpw_recover':
				$email = sanitize_email($_POST['kpw_email']);
				if(isset($email)){
					if(is_email($email)){
						if(kpw_user_exists($email)){
							if ($response = kpw_get_recovery_key($email)){
								$login = $response['token'];
								$key = $response['user_id'];
								$fid = get_option('kpw-recovery-final-page', false);
								$link = esc_url_raw(kpw_get_link('kpw-recovery-final-page')."?kpw_key=$key&kpw_login=" . rawurlencode($login))."\r\n";
								$message = 'Click on the following link or copy/paste into your browser to reset your password. It will only be valid for the next 30 minutes.'."\r\n".$link;
								
								wp_mail($email, 'Password reset link', $message);
							}
							else break;
							
						}
						else $kpw_response = new Response('Email address does not exist. Please recheck and try again.', Response::WARNING);
					}
					else $kpw_response = new Response('Please enter a valid email address.', Response::WARNING);
				}
				else $kpw_response = new Response('Please enter all the required fields.', Response::WARNING);
			break;
			case 'kpw_recover_final':
				wp_verify_nonce('kpw_reset', 'kpw_reset');
				$reset_email = false;
				$email = sanitize_email($_POST['kpw_reset_email']);
				$password1 = $_POST['kpw_password1'];
				$password2 = $_POST['kpw_password2'];
				
				if(!isset($email, $password1, $password2)){
					$kpw_response = new Response('Please enter all the required fields.', Response::WARNING);
					break;
				}
				
				if(strlen($password1) >= 6){
					if($password1 === $password2){
						if(kpw_user_exists($email)){
							if(kpw_set_password($email, $password)){
							
								$link = kpw_get_link('kpw-login-page');
								
								$kpw_response = new Response('Your password has been successfully reset. Click <a href="'.$link.'">here</a> to log in.', Response::MESSAGE);
							}
							else $kpw_response = new Response('An unexpected error occurred and the password was not reset. Please try again.', Response::ERROR);
						}
					}
					else $kpw_response = new Response('Passwords do not match', Response::ERROR);
				}
				else $kpw_response = new Response('Password must be at least 6 characters long.', Response::WARNING);
			break;
			case 'kpw_subscribe':
				$email = sanitize_email($_POST['kpw_email']);
				$plan = $_POST['kpw_plan'];
				
				if(!isset($email, $plan)){
					$kpw_response = new Response('Please enter all the required fields.', Response::WARNING);
					break;
				}
				
				$kpw_options = get_option('kpw_options');
				if(isset($kpw_options['paynow_key'], $kpw_options['paynow_id'])){
					$paynow = new Paynow\Payments\Paynow(
							$kpw_options['paynow_id'],
							$kpw_options['paynow_key']
					);
					
					if($plan === 'year' && isset($kpw_options['annual_fee'])){
						if($kpw_options['annual_fee']){
							$amount = (float) $kpw_options['annual_fee'];
						}
						else break;
					}
					elseif($plan === 'month' && isset($kpw_options['monthly_fee'])){
						if($kpw_options['monthly_fee']){
							$amount = (float) $kpw_options['monthly_fee'];
						}
						else break;
					}
					else break;
					
					$query_result = $wpdb->insert($paynow_payment_table, array('email'=>$email, 'amount' => $amount), array('%s', '%f'));
					if($query_result === 1){
						$reference = (string) $wpdb->insert_id;
						$resulturl = plugins_url("gateway/paynow/update.php?kpw_paynow_transid=$reference&kpw_plan=$plan", __FILE__ );
						
						$paynow->setResultUrl($resulturl);
						$paynow->setReturnUrl(get_page_link().'?kpw_gateway=paynow&kpw_transid='.$reference);
						
						$payment = $paynow->createPayment('KPW'.$reference, $email);
						$payment->add('Content Subscription', $amount);
						$response = $paynow->send($payment);
						
						if($response->success()){
							$link = $response->redirectUrl();
							$pollUrl = $response->pollUrl();
							
							$wpdb->update($paynow_payment_table, array('poll_url'=>$pollUrl), array('id'=>$reference),'%s', '%d');
							wp_redirect($link);
							exit;
						}
						else $kpw_response = new Response('Failed to connect to payment processor (Paynow). Please try again later.', Response::ERROR);
					}
					
				}
			break;
		}
	}
	elseif(isset($_GET['kpw_gateway'], $_GET['kpw_transid'])){
		if($_GET['kpw_gateway']==='paynow'){
			$query = $wpdb->prepare("SELECT paid FROM $paynow_payment_table WHERE id = %d", $_GET['kpw_transid']);
			$paid = (intval($wpdb->get_var($query, 0, 0)) === 1);
			if($paid) $kpw_response = new Response('Thank you for your payment', Response::MESSAGE);
			else $kpw_response = new Response('Payment not yet received. You can refresh this page if you have already paid.', Response::MESSAGE);
		}
	}
	elseif(isset($_GET['kpw_login'], $_GET['kpw_key'])){
		if($email = kpw_check_recovery_key($_GET['kpw_key'], $_GET['kpw_login'])){
			$reset_nonce = wp_nonce_field('kpw_reset', 'kpw_reset', true, false);
			$reset_email = $email;
		}
		else {
			$kpw_response =  new Response('This password reset link not valid or it has expired. Please try again.', Response::ERROR);
		}
	}
	else {
		$_SESSION['kpw_history'] = $_SERVER['HTTP_REFERER'];
	}
	
	if(!is_user_logged_in()){
			//paywall is active
			kpw_log('paywall active');
	}
});

add_action( 'admin_menu', function(){
        //to-do: insert plugin icon
	add_menu_page( 'Kainet Paywall', 'Kainet',
	'manage_options', 'kpw_main_menu', 'kpw_main_plugin_page',
	plugins_url( '/images/wordpress.png', __FILE__ ) );
	
	add_submenu_page('kpw_main_menu', 'Paywall Help', 'Help', 'manage_options', 'kpw_help_menu', 'kpw_help_plugin_page');
	
	add_action( 'admin_init', function(){
		register_setting( 'kpw-settings-group', 'kpw_options', ['type' => 'array', 'sanitize_callback' => 'kpw_sanitize_options'] );
		kpw_create_frontend_pages();
	});
});

add_action( 'admin_enqueue_scripts', function($hook){
	if(stristr($hook, 'kpw_main_menu')){
		wp_enqueue_style( 'kpw-grid',plugin_dir_url(__FILE__).'css/mabhena.min.css');
	}
});

add_action( 'wp_enqueue_scripts', function($hook){
	//wp_enqueue_style( 'reset-this',plugin_dir_url(__FILE__).'css/reset-this.css');
}, 11);
add_action( 'wp_enqueue_scripts', function($hook){
	wp_enqueue_style( 'kp',plugin_dir_url(__FILE__).'css/kpw-paywall-front.min.css');
}, 12);
add_action( 'wp_enqueue_scripts', function($hook){
	wp_enqueue_style( 'kpx',plugin_dir_url(__FILE__).'css/kpw-paywall-print.css', array(), false, 'print');
}, 13);

$kpw_sp = new Snippets(plugin_dir_path(__FILE__));
add_shortcode('paywall', function(){
	global $kpw_response;
	
	global $kpw_sp;
	return $kpw_sp->popup($kpw_response);
});
add_shortcode(LOGINSC, function(){
	global $kpw_response;
	
	global $kpw_sp;
	
	Response::$links = array(
							'forgot' => kpw_get_link('kpw-recovery-page'),
							'create' =>  kpw_get_link('kpw-signup-page')
							);
	return $kpw_sp->login($kpw_response);
});
add_shortcode(RECOVERYSC, function(){
	global $kpw_response;
	
	global $kpw_sp;
	
	Response::$links = array(
							'login' => kpw_get_link('kpw-login-page'),
							'create' =>  kpw_get_link('kpw-signup-page')
							);
	return $kpw_sp->recover($kpw_response);
});
add_shortcode(RECOVERYFSC, function(){
	global $reset_nonce;
	global $reset_email;
	global $kpw_response;
	
	global $kpw_sp;
	
	Response::$links = array(
							'login' => kpw_get_link('kpw-login-page'),
							'create' =>  kpw_get_link('kpw-signup-page')
							);
	return $kpw_sp->recoverF($kpw_response, $reset_email, $reset_nonce);
});
add_shortcode(SIGNUPSC, function(){
	global $kpw_response;
	
	global $kpw_sp;
	
	Response::$links = array(
							'forgot' => kpw_get_link('kpw-recovery-page'),
							'login' =>  kpw_get_link('kpw-login-page')
							);
	return $kpw_sp->signup($kpw_response);
});

add_shortcode(SUBSCRIBESC, function(){
	global $kpw_response;
	
	global $kpw_sp;
	
	Response::$links = array(
							'forgot' => kpw_get_link('kpw-recovery-page'),
							'login' =>  kpw_get_link('kpw-login-page')
							);
	return $kpw_sp->subscribe($kpw_response);
});

register_activation_hook( __FILE__, function(){
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	global $subscription_table, $paynow_payment_table, $subscriber_table, $password_recovery_table, $auto_auth_table;
	
	$query_subscription = 'CREATE TABLE '.$subscription_table.' (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(30) NOT NULL, amount DECIMAL(7,2) NOT NULL, start DATETIME NOT NULL DEFAULT NOW(), expires DATETIME NOT NULL, scope ENUM(\'site-wide\', \'per-article\') DEFAULT \'site-wide\', post VARCHAR(255) DEFAULT NULL)';
	$query_paynow_payment = 'CREATE TABLE '.$paynow_payment_table.' (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, created DATETIME NOT NULL DEFAULT NOW(), email VARCHAR(30) NOT NULL, amount DECIMAL(7,2) NOT NULL, paid BOOLEAN NOT NULL DEFAULT FALSE, poll_url VARCHAR(255))';
	//$query_subscriber = 'CREATE TABLE '.$subscriber_table.' (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(30) UNIQUE NOT NULL, first_names VARCHAR(50) NOT NULL, last_name VARCHAR(30) NOT NULL, password VARCHAR(50) NOT NULL)';
	$query_subscriber = 'CREATE TABLE '.$subscriber_table.' (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(30) UNIQUE NOT NULL, password_hash VARCHAR(255) NOT NULL)';
	$query_password_recovery = 'CREATE TABLE '.$password_recovery_table.' (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNIQUE NOT NULL, created DATETIME NOT NULL DEFAULT NOW(), token_hash VARCHAR(255) NOT NULL)';
	$query_auto_auth = 'CREATE TABLE '.$auto_auth_table.' (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, created DATETIME NOT NULL DEFAULT NOW(), token_hash VARCHAR(255) NOT NULL)';
	
	dbDelta($query_subscription);
	dbDelta($query_paynow_payment);
	dbDelta($query_subscriber);
	dbDelta($query_password_recovery);
	dbDelta($query_auto_auth);
});

function kpw_create_frontend_pages(){
	$loginsc = LOGINSC;
	$signupsc = SIGNUPSC;
	$recoverysc = RECOVERYSC;
	$recoveryfsc = RECOVERYFSC;
	$subscribesc = SUBSCRIBESC;
	
	kpw_create_page('Log In', "[$loginsc]", 'kpw-login-page');
	kpw_create_page('Sign Up', "[$signupsc]", 'kpw-signup-page');
	kpw_create_page('Recover Password', "[$recoverysc]", 'kpw-recovery-page');
	kpw_create_page('Reset Password', "[$recoveryfsc]", 'kpw-recovery-final-page');
	kpw_create_page('Subscribe', "[$subscribesc]", 'kpw-subscribe-page');
}


function kpw_create_page($post_title, $post_content, $page){
	if(post_exists($post_title, $post_content) === 0){//post doesn't exist
		$fid = get_option($page);
		$postarr = [
			'ID' => $fid? (int) $fid : null,
			'post_content' => $post_content,
			'post_title' => $post_title,
			'post_type' => 'page',
			'comment_status' => 'closed',
			'post_status' => 'publish'
		];
		$fid = wp_insert_post($postarr, true);
		is_wp_error($fid)? delete_option($page) : update_option($page, $fid);
	}
}

function kpw_sanitize_options($input){
	$input['site_email'] = sanitize_email($input['site_email']);
	$input['site_name'] = sanitize_text_field($input['site_name']);
	$input['monthly_fee'] = floatval($input['monthly_fee']);
	$input['annual_fee'] = floatval($input['annual_fee']);
	return $input;
}

function hasSubscription($email){
	global $subscription_table;
	global $wpdb;
	$query = $wpdb->prepare("SELECT COUNT(*) FROM $subscription_table WHERE email = %s AND expires > NOW()", $email);
	return $wpdb->get_var($query, 0, 0) > 0;
} 

function kpw_get_link($page_name){
	$fid = get_option($page_name, false);
	$link = get_permalink($fid);
	
	return $link;
}

/**/
function kpw_check_password($email, $password){
	global $subscriber_table, $wpdb;
	kpw_log($password_hash);
	$query = $wpdb->prepare("SELECT id, password_hash FROM $subscriber_table WHERE email = %s", $email);
	$password_hash = (string) $wpdb->get_var($query, 1, 0);
	if(wp_check_password($password, $password_hash)){
		return $wpdb->get_var($query, 0, 0);
	}
	return false;
}
function kpw_user_exists($email){
	global $subscriber_table, $wpdb;
	
	$query = $wpdb->prepare("SELECT COUNT(*) FROM $subscriber_table WHERE email = %s", $email);
	return $wpdb->get_var($query, 0, 0) > 0;
}
function kpw_login($email, $password, $remember = true){
	if($user_id = kpw_check_password($email, $password)){
		$_SESSION['kpw_user_id'] = $user_id;
		if($remember) kpw_set_login_cookie($user_id);
		return true;
	}
	else return false;
}
function kpw_set_password($email, $password){
	global $subscriber_table, $wpdb;
	
	$password_hash = wp_hash_password($password);
	return $wpdb->update($subscriber_table, array('password_hash'=>$password), array('email'=>$email));
}
function kpw_logged_in(){
	if($_SESSION['kpw_user_id'] !== false) return true;
	else return false;
}
function kpw_logout(){
	$_SESSION['kpw_user_id'] = false;
	
	setcookie ('kpw_user_id', '', time() - 3600);
	setcookie ('kpw_token', '', time() - 3600);
}
function kpw_set_login_cookie($user_id){
	global $auto_auth_table, $wpdb;
	
	$token = wp_generate_password();
	$token_hash = wp_hash_password($token);
	
	$inserted = (int) $wpdb->insert($auto_auth_table, array('user_id' => $user_id, 'token_hash' => $token_hash), array('%d', '%s'));
	if($inserted){
		$expire = time() + 60*60*24*7;//cookie expires after 10 days
		return (
			setcookie('kpw_user_id', $user_id, $expire)
			&&
			setcookie('kpw_token', $token, $expire)
		);
	}
	else return false;
}

function kpw_add_user($email, $password){
	global $subscriber_table, $wpdb;
	$password_hash = wp_hash_password($password);
	
	return $wpdb->insert($subscriber_table, array('email'=>$email, 'password_hash'=>$password_hash), array('%s','%s'));
}
function kpw_get_recovery_key($email){
	global $password_recovery_table, $subscriber_table, $wpdb;
	
	$token = wp_generate_password();
	$token_hash = wp_hash_password($token);
	
	$query = $wpdb->prepare("SELECT id FROM $subscriber_table WHERE email = %s", $email);
	$user_id = (int) $wpdb->get_var($query, 0, 0);
	if($user_id){
		$insert_query = $wpdb->prepare("INSERT INTO $password_recovery_table(user_id, created, token_hash) VALUES(%d, NOW(), %s) ON DUPLICATE KEY UPDATE", $user_id, $token_hash);
		//$inserted = (int) $wpdb->insert($password_recovery_table, array('user_id'=>$user_id, 'token_hash'=>$token_hash), array('%d','%s'));
		$inserted = (int) $wpdb->query($insert_query);
		if($inserted > 0)
			return array ('token' => $token,
				'user_id' => $user_id
			);
		else return false;
	}
	else return false;
}
function kpw_check_recovery_key($user_id, $token){
	global $password_recovery_table, $subscriber_table, $wpdb;
	
	$query = $wpdb->prepare("SELECT S.email, R.token_hash FROM $password_recovery_table R, $subscriber_table S WHERE R.user_id = %d AND S.id = R.user_id AND DATE_ADD(R.created, INTERVAL 30 MINUTE) > NOW()", $user_id);
	$token_hash = (string) $wpdb->get_var($query, 1, 0);
	if(wp_check_password($token, $token_hash)){
		return $wpdb->get_var($query, 0, 0);
	}
	return false;
}
function kpw_check_cookie(){
	global $auto_auth_table, $wpdb;
	
	$user_id = $_COOKIE['kpw_user_id'];
	$token = $_COOKIE['kpw_token'];
	
	$query = $wpdb->prepare("SELECT token_hash FROM $auto_auth_table WHERE user_id = %d ", $user_id);
	$token_hash = (string) $wpdb->get_var($query, 0, 0);
	if (wp_check_password($token, $token_hash)) return $user_id;
	else return false;
}

function kpw_main_plugin_page(){
?>
<div class="spectre-container">
	<form method="post" action="options.php" class="spectre-form-horizontal">
		<?php settings_fields( 'kpw-settings-group' ); 
			
		?>
		<?php $kpw_options = get_option( 'kpw_options' ); 
			if (!$kpw_options){
				$kpw_options = [
					'scope' => '',
					'monthly_fee' => '',
					'annual_fee' => '',
					'paynow_key' => '',
					'paynow_id'=> '',
					'paynow_express' => '',
					'site_name' => '',
					'site_email' => ''
				];
			}
		?>
		<h5>Paywall scope</h5>
		<div class="spectre-form-group">
		  <label class="spectre-form-radio spectre-form-inline" title="Subscription gives access to entire site">
<input type="radio" name="kpw_options[scope]" value="site_wide" <?php if($kpw_options['scope'] === 'site_wide') echo 'checked';?>>
			<i class="spectre-form-icon"></i> Site-wide
		  </label>
		  <label class="spectre-form-radio spectre-form-inline" title="Access to each article requires its own subscription">
			<input type="radio" name="kpw_options[scope]" value="per_article" <?php if($kpw_options['scope'] === 'per_article') echo 'checked';?>>
			<i class="spectre-form-icon"></i> Per-article
		  </label>
		</div>
		
		<h5 title="Available subscription periods and their pricing.">Subscription Fees</h5>
		<!--<div class="form-group">
			<div class="col-2 col-xs-5">
				<label class="form-label" for="input-example-1">Week</label>
			</div>
			<div class="col-2 col-xs-6">
				 <input type="text" class="form-input" placeholder="100">
			</div>
		</div>-->
		<div class="spectre-form-group">
			<div class="spectre-col-2 spectre-col-xs-5">
				<label class="spectre-form-label" for="input-example-1" title="Monthly subscription fee">Monthly</label>
			</div>
			<div class="spectre-col-2 spectre-col-xs-6">
				 <div class="spectre-input-group">
					 <span class="spectre-input-group-addon">ZWL</span>
					 <input type="text" class="spectre-form-input" placeholder="400" name="kpw_options[monthly_fee]" value="<?php echo esc_attr($kpw_options['monthly_fee']); ?>">
				 </div>
			</div>
		</div>
		<div class="spectre-form-group">
			<div class="spectre-col-2 spectre-col-xs-5">
				<label class="spectre-form-label" for="input-example-1" title="Annual subscription fee">Annual</label>
			</div>
			<div class="spectre-col-2 spectre-col-xs-6">
				<div class="spectre-input-group">
					<span class="spectre-input-group-addon">ZWL</span>
					<input type="text" class="spectre-form-input" placeholder="4800" name="kpw_options[annual_fee]" value="<?php echo esc_attr($kpw_options['annual_fee']); ?>">
				</div>
			</div>
		</div>
		
		<h5><a href="http://www.paynow.co.zw">Paynow Details</a></h5>
		<div class="spectre-form-group">
			<div class="spectre-col-2 spectre-col-xs-5">
				<label class="spectre-form-label" for="input-example-1" title="Paynow Integration Key">Integration Key</label>
			</div>
			<div class="spectre-col-3 spectre-col-xs-7">
				<input class="spectre-form-input" type="text" id="input-example-1" required name="kpw_options[paynow_key]" value="<?php echo esc_attr($kpw_options['paynow_key']); ?>">
			</div>
		</div>
		<div class="spectre-form-group">
			<div class="spectre-col-2 spectre-col-xs-5">
				<label class="spectre-form-label" for="input-example-1" title="Paynow Integration ID">Integration ID</label>
			</div>
			<div class="spectre-col-3 spectre-col-xs-7">
				<input class="spectre-form-input" type="text" id="input-example-1" required name="kpw_options[paynow_id]" value="<?php echo esc_attr($kpw_options['paynow_id']); ?>">
			</div>
		</div>
		<div class="spectre-form-group">
		  <label class="spectre-form-switch" title="Initiate mobile payments without redirecting to Paynow.">
			<input type="checkbox" name="kpw_options[paynow_express]" <?php if($kpw_options['paynow_express']) echo 'checked'; ?> >
			<i class="spectre-form-icon"></i> Enable Express Checkout
		  </label>
		</div>
		<h5>Site Details</h5>
		<div class="spectre-form-group">
			<div class="spectre-col-2 spectre-col-xs-5">
				<label class="spectre-form-label" for="input-example-1">Site Name</label>
			</div>
			<div class="spectre-col-3 spectre-col-xs-7">
				<input class="spectre-form-input" type="text" id="input-example-1" required name="kpw_options[site_name]" value="<?php echo esc_attr($kpw_options['site_name']); ?>">
			</div>
		</div>
		<div class="spectre-form-group">
			<div class="spectre-col-2 spectre-col-xs-5">
				<label class="spectre-form-label" for="input-example-1" title="Existing address to send automated emails from.">Site Email</label>
			</div>
			<div class="spectre-col-3 spectre-col-xs-7">
				<div class="spectre-input-group">
					<input class="spectre-form-input" type="email" id="input-example-1" required name="kpw_options[site_email]" value="<?php echo esc_attr($kpw_options['site_email']); ?>">
				</div>
			</div>
		</div>
		<button onclick="testEmail();" class="spectre-btn spectre-btn-link">Send Test Email</button>
		<div class="spectre-col-4 spectre-col-mx-auto">
			<input type="submit" class="spectre-btn spectre-btn-primary" value="Save"/>
		</div>
	</form>
</div>
<script type="text/javascript">
	function testEmail(){
		let address = prompt("Enter recipient email address");
	}
</script>
<?php
}
function kpw_help_plugin_page(){
?>
	<h1>Help</h1>
<?php	
}
?>