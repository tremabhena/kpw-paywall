<?php
/*
Plugin Name: KPW Paywall
*/
//to-do: fix css reset issue and sizing
require_once('vendor/autoload.php');
use Kpw\Snippets;
use Kpw\Response;

//remove this
function kpw_log($message){
	file_put_contents('kpw-log.txt', date('Y-m-d H:i:s').' '.$message."\r\n", FILE_APPEND);
}

const LOGINSC = 'kpw-login';
const RECOVERYSC = 'kpw-recovery';
const RECOVERYFSC = 'kpw-recoveryf';
const SIGNUPSC = 'kpw-signup';
const SUBSCRIBESC = 'kpw-subscribe';

session_start();
if(!isset($_SESSION['kpw_user_id'])) $_SESSION['kpw_user_id'] = false;
if(!isset($_SESSION['kpw_user_email'])) $_SESSION['kpw_user_email'] = false;
if(!isset($_SESSION['kpw_history'])) $_SESSION['kpw_history'] = false;
if(!isset($_SESSION['kpw_boomerang'])) $_SESSION['kpw_boomerang'] = array();
if(!isset($_SESSION['kpw_reset_email'])) $_SESSION['kpw_reset_email'] = false;

//$kpw_response = new Response();
$kpw_response = null;

global $wpdb;
global $subscription_table, $paynow_payment_table, $subscriber_table, $password_recovery_table, $auto_auth_table;

$subscription_table = $wpdb->prefix .'kpw_subscription';
$paynow_payment_table = $wpdb->prefix .'kpw_paynow_payment';
$subscriber_table = $wpdb->prefix .'kpw_subscriber';
$password_recovery_table = $wpdb->prefix.'kpw_password_recovery';
$auto_auth_table = $wpdb->prefix.'kpw_automatic_authentication';

add_action('init', function(){
	global $kpw_response;
	
	//attempt to log in via cookie
	if(!$_SESSION['kpw_user_id']){
		if(isset($_COOKIE['kpw_user_id'], $_COOKIE['kpw_token'])){
			if($user = kpw_check_cookie()){
				$_SESSION['kpw_user_id'] = $user['user_id'];
				$_SESSION['kpw_user_email'] = $user['email'];
			}
		}
	}
	
	if(isset($_POST['kpw_current_form'])){//is it kpw form submission?
		//Handle form submissions from pages created by plug in
		switch($_POST['kpw_current_form']){
			case 'kpw_signin':
				$email = $_POST['kpw_email'];
				$password = $_POST['kpw_password'];
				
				if(!isset($email, $password)){
					$kpw_response = new Response('Please enter your user email and password.', Response::WARNING);
					break;
				}
				
				if(is_email($email)){
					if(strlen($password)>=6 && strlen($password)<30){
						kpw_logout();
						if(kpw_login($email, $password)){//succesfully signed in
							if(!kpw_has_subscription($email)) {
								wp_redirect(kpw_get_link('kpw-subscribe-page'));
								exit;
							}
							elseif(isset($_SESSION['kpw_history'])){
								wp_redirect($_SESSION['kpw_history']);//go back to the last viewed article
								//$_SESSION['kpw_history'] = false;
								exit;
							}
							else {
								wp_redirect(home_url());
								exit;
							}
						}
						else $kpw_response = new Response('Wrong password/username combination. Please try again.', Response::ERROR);
					}
					else $kpw_response = new Response('Your password must be at least 6 characters long.', Response::WARNING);			
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
				
				if(strlen($password1) >= 6 && strlen($password)<30){
					if($password1 === $password2){
						if(!kpw_user_exists($email)){//check that user doesnt already exist
							$user = kpw_add_user($email, $password1);
							if($user){//succesfully signed up
								/*if(isset($_SESSION['kpw_boomerang']['signup'])){
									kpw_log('Boomerang is set');
									//wp_redirect($_SESSION['kpw_boomerang']['signup']);
									$kpw_response = new Response('Your account has been succesfully created. Click <a href="'.$_SESSION['kpw_boomerang']['signup'].'">here</a> to return to what you were viewing before.', Response::MESSAGE);
									unset($_SESSION['kpw_boomerang']['signup']);
									//exit;
								}*/
								if(!kpw_logged_in()) {
									$kpw_response = new Response('Your subscription account has been succesfully created. Click <a href="'.kpw_get_link('kpw-login-page').'">here</a> to proceed to the log in page.', Response::MESSAGE);
									//wp_redirect(kpw_get_link('kpw-login-page'));
									//exit;
								}
								elseif($_SESSION['kpw_history']){
									$kpw_response = new Response('Your account has been succesfully created. Click <a href="'.$_SESSION['kpw_history'].'">here</a> to return to what you were trying to view earlier.', Response::MESSAGE);
									//wp_redirect($_SESSION['kpw_history']);
									//$_SESSION['kpw_history'] = false;
									//exit;
								}
								else {
									$kpw_response = new Response('Your account has been succesfully created. Click <a href="'.home_url().'">here</a> to continue.', Response::MESSAGE);
									//wp_redirect(home_url());
									//exit;
								}
							}
							else $kpw_response = new Response('Failed to create user. Please try again later.', Response::ERROR);
						}
						else $kpw_response = new Response('User with this email address already exists.', Response::WARNING);
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
								$link = esc_url_raw(kpw_get_link('kpw-recovery-final-page')."?kpwk=$key&kpwv=".rawurlencode($login))."\r\n";
								$message = 'Please click on the following link (or paste it into your browser) in order to reset your password. It will only be valid for the next 30 minutes.'."\r\n".$link;
								if(kpw_mail($email, 'Password reset link', $message)){
									$kpw_response = new Response('An email with the password reset link has been sent. Try also looking in the spam folder if you cannot find it in your inbox.<br/>Click <a href="'.get_permalink().'">here</a> to resend if you still cannot find it.', Response::MESSAGE);
								}
								else $kpw_response = new Response('Failed to send the password reset email. Please try again later.', Response::ERROR);
							}
							else {
								$kpw_response = new Response('An unexpected error has occurred. Please try again.', Response::ERROR);
							}
						}
						else $kpw_response = new Response('User with that email address does not exist. Please recheck and try again or <a href="'.kpw_get_link('kpw-signup-page').'">create</a> a new account.', Response::WARNING);
					}
					else $kpw_response = new Response('Please enter a valid email address.', Response::WARNING);
				}
				else $kpw_response = new Response('Please enter all the required fields.', Response::WARNING);
			break;
			case 'kpw_recover_final':
				if(!is_email($_SESSION['kpw_reset_email'])){
					wp_redirect(kpw_get_link('kpw-recovery-page'));
					exit;
				}
				$email = $_SESSION['kpw_reset_email'];
				$_SESSION['kpw_reset_email'] = false;
				
				$password1 = $_POST['kpw_password1'];
				$password2 = $_POST['kpw_password2'];
				
				if(!isset($password1, $password2)){
					$kpw_response = new Response('Please enter all the required information.', Response::WARNING);
					break;
				}
				
				if(strlen($password1) >= 6){
					if($password1 === $password2){
						if($user_id = kpw_user_exists($email)){
							if(kpw_set_password($email, $password1)){
								$link = kpw_get_link('kpw-login-page');
								$kpw_response = new Response('Your password has been successfully reset. Click <a href="'.$link.'">here</a> to log in.', Response::MESSAGE);
								kpw_delete_recovery_key($user_id);
							}
							else $kpw_response = new Response('An unexpected error occurred and the password was not reset. Please try again.', Response::ERROR);
						}
					}
					else $kpw_response = new Response('Passwords do not match', Response::ERROR);
				}
				else $kpw_response = new Response('Password must be at least 6 characters long.', Response::WARNING);
			break;
			case 'kpw_subscribe':
				if(!kpw_logged_in()){
					wp_redirect(kpw_get_link('kpw-login-page'));
					exit;
				}
				$email = sanitize_email($_POST['kpw_email']);
				$plan = $_POST['kpw_plan'];
				
				if(!isset($email, $plan)){
					$kpw_response = new Response('Please provide all the required information.', Response::WARNING);
					break;
				}
				if(!is_email($email)){
					$kpw_response = new Response('Please enter a valid email address.', Response::WARNING);
					break;
				}
				$kpw_options = get_option('kpw_options');
				if(isset($kpw_options['paynow_key'], $kpw_options['paynow_id'])){
					$paynow = new Paynow\Payments\Paynow(
							$kpw_options['paynow_id'],
							$kpw_options['paynow_key'],
							'',
							''
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
					
					if(kpw_add_paynow_transaction($_SESSION['kpw_user_email'], $amount) === 1){
						global $wpdb;
						$reference = (string) $wpdb->insert_id;
						$resulturl = plugins_url("gateway/paynow/update.php?kpw_paynow_transid=$reference&kpw_plan=$plan", __FILE__ );
						$paynow->setResultUrl($resulturl);
						$paynow->setReturnUrl(kpw_get_link('kpw-subscribe-page').'?kpw_gateway=paynow&kpw_transid='.$reference);
						$payment = $paynow->createPayment('KPW Subscription #'.$reference, $email);
						$payment->add('Content Subscription', $amount);
						$response = $paynow->send($payment);
						
						if($response->success()){
							$link = $response->redirectUrl();
							$pollUrl = $response->pollUrl();
							
							kpw_update_paynow_transaction($pollUrl, $reference);
							$kpw_response = new Response('Please click <a href="'.$link.'">here</a> to continue to Paynow.', Response::MESSAGE);
							//wp_redirect($link);
							//exit;
						}
						else $kpw_response = new Response('Failed to connect to payment processor (Paynow). Please try again later.', Response::ERROR);
					}
					
				}
				else $kpw_response = new Response('This site unable to accept payments at this time. Please try again later.', Response::ERROR);
			break;
		}
	}
	elseif(isset($_GET['kpw_gateway'], $_GET['kpw_transid'])){//redirected from Paynow
		if($_GET['kpw_gateway']==='paynow'){
			if(kpw_paynow_paid($_GET['kpw_transid'])) $kpw_response = new Response('Thank you for your payment.', Response::MESSAGE);
			else $kpw_response = new Response('Payment not yet received. You can refresh this page if you have already paid.', Response::MESSAGE);
		}
	}
	elseif(isset($_GET['kpwk'], $_GET['kpwv'])){//after clicking on password recovery link
		if($email = kpw_check_recovery_key($_GET['kpwk'], $_GET['kpwv'])){
			$_SESSION['kpw_reset_email'] = $email;
		}
		else {
			$_SESSION['kpw_reset_email'] = false;
			$kpw_response =  new Response('This password reset link is invalid or it has expired. Please <a href="'.kpw_get_link('kpw-recovery-page').'">click here</a> to generate another one.', Response::MESSAGE);
		}
	}
});

//This filter prevents the pages generated by the plugin from showing up on most sites themes
add_filter('get_pages', function($pages){
	return array_filter(
			$pages,
			function($page){
				$page_ids = kpw_get_pages();
				$flipped_page_ids = array_flip($page_ids);
				
				return !array_key_exists($page->ID, $flipped_page_ids);
			});	
});
//Front-end paywall logic goes here
//Use as dummy for time-being
add_filter('the_content', function($content){
	if(!is_user_logged_in()){//disable paywall for logged in Wordpress users
		$post = get_post();
		$kpw_active = get_post_meta($post->ID, 'kpw_active', true);
		if($kpw_active === 'active'){
			$page_ids = kpw_get_pages();
			if(!is_page($page_ids)){//only active on normal site pages
				if($_SESSION['kpw_user_id']){
					if(!kpw_has_subscription()){//no active subscription
						return 'Your subscription has run out. Click <a href="'.kpw_get_link('kpw_subscribe-page').'">here</a> to renew your subscription or <a href="'.kpw_get_link('kpw-login-page').'">here</a> to use a different account';
					}
				}
				else return 'You are not logged in. Click <a href="'.kpw_get_link('kpw-login-page').'">here</a> to log in or <a href="'.kpw_get_link('kpw-signup-page').'">Sign up</a>';
			}
		}
	}
	return $content;
});
//keeps track of the last site page/article that visitor was on
add_action('loop_start', function(){
	if(in_the_loop()){
		$page_ids = kpw_get_pages();
		if(!is_page($page_ids)) {
			$_SESSION['kpw_history'] = get_permalink();
		}
	}
});

add_action( 'add_meta_boxes', function(){
	add_meta_box( 'kpw-meta', 'KPW Paywall', function($post, $box){
		$kpw_active = get_post_meta($post->ID, 'kpw_active', true);
		wp_nonce_field( plugin_basename( __FILE__ ), 'kpw_save_meta_box' );
		?>
		<label><input type="radio" name="kpw_paywall_active" value="inactive" <?php if($kpw_active !== 'active') echo 'checked'; ?>>Not Active on this post</label>
		<br/>
		<label><input type="radio" name="kpw_paywall_active" value="active" <?php if($kpw_active === 'active') echo 'checked'; ?>>Active on this post</label>
		<?php
}
, 'post','normal', 'high', $callback_args );
});
add_action('save_post', function($post_id){
	if(isset($_POST['kpw_paywall_active'])){
		// if auto saving skip saving our meta box data
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		//check nonce for security
		wp_verify_nonce( plugin_basename( __FILE__ ), 'kpw_save_meta_box' );
		update_post_meta( $post_id, 'kpw_active', sanitize_text_field( $_POST['kpw_paywall_active'] ) );
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
//might need to remove this shortcode and opt for meta-based paywalls
$kpw_sp = new Snippets(plugin_dir_path(__FILE__));
add_shortcode('paywall', function(){
	global $kpw_response, $kpw_sp;
	
	return $kpw_sp->popup($kpw_response);
});
add_shortcode(LOGINSC, function(){
	global $kpw_response, $kpw_sp;
	
	Response::$links = array(
							'forgot' => kpw_get_link('kpw-recovery-page'),
							'create' =>  kpw_get_link('kpw-signup-page')
							);
	return $kpw_sp->login($kpw_response);
});
add_shortcode(RECOVERYSC, function(){
	global $kpw_response, $kpw_sp;
	
	Response::$links = array(
							'login' => kpw_get_link('kpw-login-page'),
							'create' =>  kpw_get_link('kpw-signup-page')
							);
	return $kpw_sp->recover($kpw_response);
});
add_shortcode(RECOVERYFSC, function(){
	global $kpw_response, $kpw_sp;
	
	Response::$links = array(
							'login' => kpw_get_link('kpw-login-page'),
							'create' =>  kpw_get_link('kpw-signup-page')
							);
	return $kpw_sp->recoverF($kpw_response);
});
add_shortcode(SIGNUPSC, function(){
	global $kpw_response, $kpw_sp;
	
	Response::$links = array(
							'forgot' => kpw_get_link('kpw-recovery-page'),
							'login' =>  kpw_get_link('kpw-login-page')
							);
	return $kpw_sp->signup($kpw_response);
});

add_shortcode(SUBSCRIBESC, function(){
	global $kpw_response, $kpw_sp, $subscriber_table, $wpdb;

	Response::$links = array(
							'create' => kpw_get_link('kpw-signup-page'),
							'login' =>  kpw_get_link('kpw-login-page')
							);
	
	$kpw_options = get_option( 'kpw_options' ); 
	$monthly_fee = $kpw_options['monthly_fee'];
	$annual_fee = $kpw_options['annual_fee'];
	return $kpw_sp->subscribe($kpw_response, $_SESSION['kpw_user_email'], $monthly_fee, $annual_fee);
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
		
		if(is_wp_error($fid)) delete_option($page);
		else update_option($page, $fid);
	}
}

function kpw_sanitize_options($input){
	$input['site_email'] = sanitize_email($input['site_email']);
	$input['site_name'] = sanitize_text_field($input['site_name']);
	$input['monthly_fee'] = floatval($input['monthly_fee']);
	$input['annual_fee'] = floatval($input['annual_fee']);
	return $input;
}

function kpw_has_subscription(){
	global $subscription_table, $subscriber_table, $wpdb;
	if($_SESSION['kpw_user_id']){
		$query = $wpdb->prepare("SELECT N.expires FROM $subscription_table N, $subscriber_table R  WHERE R.id = %d AND N.email = R.email AND N.expires > NOW()", $_SESSION['kpw_user_id']);
		return $wpdb->get_var($query, 0, 0);
	}
	else return false;
} 

function kpw_get_link($page_name){
	$fid = get_option($page_name, false);
	$link = get_permalink($fid);
	
	return $link;
}

//pages generated and used by plugin
function kpw_get_pages(){
	$plugin_pages = [
					'kpw-login-page',
					'kpw-signup-page',
					'kpw-recovery-page',
					'kpw-recovery-final-page',
					'kpw-subscribe-page'
				];
	return array_filter(
				array_map(function($plugin_page){
						return get_option($plugin_page, false);
					}, $plugin_pages)
			);
}
/*Should try to optimise these db querying functions as much as possible*/
function kpw_check_password($email, $password){
	global $subscriber_table, $wpdb;
	
	$query = $wpdb->prepare("SELECT id, password_hash FROM $subscriber_table WHERE email = %s", $email);
	$password_hash = (string) $wpdb->get_var($query, 1, 0);
	if(wp_check_password($password, $password_hash)){
		return $wpdb->get_var($query, 0, 0);
	}
	return false;
}
function kpw_user_exists($email){
	global $subscriber_table, $wpdb;
	
	$query = $wpdb->prepare("SELECT id FROM $subscriber_table WHERE email = %s", $email);
	return $wpdb->get_var($query, 0, 0);
}
function kpw_login($email, $password, $remember = true){
	if($user_id = kpw_check_password($email, $password)){
		$_SESSION['kpw_user_id'] = $user_id;
		$_SESSION['kpw_user_email'] = $email;
		if($remember) kpw_set_login_cookie($user_id);
		return true;
	}
	else return false;
}
function kpw_set_password($email, $password){
	global $subscriber_table, $wpdb;
	$password_hash = wp_hash_password($password);
	return $wpdb->update($subscriber_table, array('password_hash'=>$password_hash), array('email'=>$email));
}
function kpw_logged_in(){
	if($_SESSION['kpw_user_id'] !== false && is_email($_SESSION['kpw_user_email'])) return true;
	else return false;
}
function kpw_logout(){
	$_SESSION['kpw_user_id'] = false;
	$_SESSION['kpw_user_email'] = false;
	
	setcookie ('kpw_user_id', '', time() - 3600);
	setcookie ('kpw_token', '', time() - 3600);
}
function kpw_set_login_cookie($user_id){
	global $auto_auth_table, $wpdb;
	
	$token = wp_generate_password();
	$token_hash = wp_hash_password($token);
	
	$inserted = (int) $wpdb->insert($auto_auth_table, array('user_id' => $user_id, 'token_hash' => $token_hash), array('%d', '%s'));
	if($inserted){
		$expire = time() + 60*60*24*10;//cookie expires after 10 days
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
		$inserted = (int) $wpdb->replace($password_recovery_table, array('user_id'=>$user_id, 'token_hash'=>$token_hash), array('%d', '%s'));
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

function kpw_delete_recovery_key($user_id){
	global $password_recovery_table, $subscriber_table, $wpdb;
	
	$wpdb->delete($password_recovery_table, array('user_id'=>$user_id), '%d');
}

function kpw_check_cookie(){
	global $auto_auth_table, $subscriber_table, $wpdb;
	
	$user_id = $_COOKIE['kpw_user_id'];
	$token = $_COOKIE['kpw_token'];
	
	$query = $wpdb->prepare("SELECT A.token_hash, S.email FROM $auto_auth_table A, $subscriber_table S  WHERE A.user_id = %d AND A.user_id = S.id", $user_id);
	if($rows =  $wpdb->get_results($query, ARRAY_A)){
		foreach($rows as $row){
			$token_hash = $row['token_hash'];
			$email = $row['email'];
			if (wp_check_password($token, $token_hash)) return array('email' => $email, 'user_id' => $user_id);
		}
	}
	else return false;
}

function kpw_mail($to, $subject, $message){
	function kpw_mail_address($from_email){
		$kpw_options = get_option('kpw_options');
		if($address = $kpw_options['site_email']){
			if(is_email($address)){
				return $address;
			}
		}
		return $from_email;
	}
	function kpw_mail_sender($from_name){
		$kpw_options = get_option('kpw_options');
		if($site_name = $kpw_options['site_name']) return $site_name;
		return $from_name;
	}
	
	add_filter('wp_mail_from', 'kpw_mail_address');
	add_filter('wp_mail_from_name', 'kpw_mail_sender');
	$response = wp_mail($to, $subject, $message);
	remove_filter('wp_mail_from_name', 'kpw_mail_sender');
	remove_filter('wp_mail_from', 'kpw_mail_address');
	
	return $response;
}

function kpw_add_paynow_transaction($email, $amount){
	global $wpdb, $paynow_payment_table;
	
	return (int) $wpdb->insert($paynow_payment_table, array('email'=>$email, 'amount' => $amount), array('%s', '%f'));
}

function kpw_update_paynow_transaction($pollUrl, $reference){
	global $wpdb, $paynow_payment_table;
	
	$wpdb->update($paynow_payment_table, array('poll_url'=>$pollUrl), array('id'=>$reference),'%s', '%d');
}
function kpw_paynow_paid($transid){{
	global $wpdb, $paynow_payment_table;
	
	$query = $wpdb->prepare("SELECT paid FROM $paynow_payment_table WHERE id = %d", $transid);
	if(intval($wpdb->get_var($query, 0, 0)) === 1) return true;
	else {
		//fix this so that it checks on its own
		return false;
	}
}
	
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