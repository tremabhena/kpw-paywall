<?php
	require_once('../../vendor/autoload.php');
	require_once(dirname( __FILE__, 6).'/wp-load.php' );
	
	kpw_log('Update has been called!!!');
	$paynow_payment_table = $wpdb->prefix .'kpw_paynow_payment';
	$subscription_table = $wpdb->prefix .'kpw_subscription';
	
	if(isset($_GET['kpw_paynow_transid'], $_GET['kpw_plan'])){
		$transid = $_GET['kpw_paynow_transid'];
		$query = $wpdb->prepare("SELECT poll_url FROM $paynow_payment_table WHERE id = %d", $transid);
		$pollUrl = $wpdb->get_var($query, 0, 0);
		
		$kpw_options = get_option('kpw_options');
		
		$paynow = new Paynow\Payments\Paynow(
				$kpw_options['paynow_id'],
				$kpw_options['paynow_key'],
				'',
				''
		);
		$status = $paynow->pollTransaction($pollUrl);
		
		if($status->paid()) {
			kpw_paynow_has_paid($_GET['kpw_plan'], $transid);
			kpw_log('Payment has been received');
		}
			/*
			if($_GET['kpw_plan'] === 'year') $interval = 12;
			elseif ($_GET['kpw_plan'] === 'month') $interval = 1;
			else exit;
			if($wpdb->update($paynow_payment_table, array('paid'=>true), array('id'=>$transid, 'paid'=>false), null, array('%d', '%d')) !== false){
				//Check for unexpired subscriptions
				$query = $wpdb->prepare("SELECT t.id FROM $subscription_table t, $paynow_payment_table p WHERE t.email = p.email AND t.expires > NOW() AND p.id = %d ORDER BY t.expires DESC LIMIT 1", $transid);
				//Get active subscription
				$t_id = $wpdb->get_var($query, 0, 0);
				if(!is_null($t_id)){
					$query = $wpdb->prepare("INSERT INTO $subscription_table (email, amount, expires) SELECT p.email, p.amount, DATE_ADD(t.expires, INTERVAL %d MONTH) FROM $paynow_payment_table p, $subscription_table t WHERE p.id = %d AND t.id = %d", $interval, $transid, $t_id);
					$wpdb->query($query);
				}
				else {
					$query = $wpdb->prepare("INSERT INTO $subscription_table (email, amount, expires) SELECT email, amount, DATE_ADD(NOW(), INTERVAL %d MONTH) FROM $paynow_payment_table WHERE id = %d", $interval, $transid);
					$wpdb->query($query);
				}
			}
			else kpw_log('Database update failed');
			*/
		else kpw_log('Status is not paid');
	}
?>