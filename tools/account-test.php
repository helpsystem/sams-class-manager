<?php
$ok=0;$bad=0;$c=function($b,$m)use(&$ok,&$bad){echo ($b?'ok ':'FAIL ').$m."\n";$b?$ok++:$bad++;};
$c(''!==SDSC_Account::problem('anna@gmial.com'),'typo domain gmial.com is sent back');
$c(false!==strpos(SDSC_Account::problem('anna@gmial.com'),'anna@gmail.com'),'typo message suggests the right address');
$c(''===SDSC_Account::problem('anna@gmail.com'),'gmail.com is accepted');
$d=SDSC_Account::domain_ok('someone@this-domain-does-not-exist-xyz-12345.com'); $c(false===$d||null===$d,'a domain that cannot receive mail is refused (or skipped when DNS is unusable): '.var_export($d,true));
$c(''===SDSC_Account::problem(''),'empty parent e-mail is fine');
// account creation: no e-mail sent, random password, student flag
$mails=0; add_filter('pre_wp_mail',function($p)use(&$mails){$mails++;return true;},1);
$em='sdsc-test-'.wp_rand(1000,9999).'@example.com';
$id=SDSC_Account::ensure($em,'Test','Student');
$c($id>0,'login is created from the registration e-mail');
$u=$id?get_userdata($id):null;
$c($u && $u->user_email===$em && get_user_meta($id,'_is_tutor_student',true),'account has the e-mail and the student flag');
$c(0===$mails,'creating the account sends no e-mail');
$c(0===SDSC_Account::ensure($em,'Test','Student'),'a second registration with the same e-mail does not create another account');
$c(false===wp_check_password('password',$u->user_pass,$id),'password is not guessable');
$block=SDSC_Account::email_block(); $c(false!==strpos($block,'correct e-mail exactly') && false!==strpos($block,'Lost your password'),'e-mail block tells how to log in and what to do about a wrong e-mail');
$c(false!==strpos(SDSC_Account::page_block(),'correct e-mail exactly'),'page block has the same wording');
// the setup e-mail goes through the notifications hold
$res=SDSC_Account::send_setup($id,'Test'); $c(1===$mails,'setup e-mail is handed to wp_mail once ('.$mails.')');
if($id){ require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($id); }

// "Create your password" page logic
$rm=new ReflectionMethod('SDSC_Account','commit'); $rm->setAccessible(true);
$cm=new ReflectionMethod('SDSC_Account','claimable'); $cm->setAccessible(true);
$A='sdsc-test-a'.wp_rand(1000,9999).'@example.com'; $B='sdsc-test-b'.wp_rand(1000,9999).'@example.com'; $C='sdsc-test-c'.wp_rand(1000,9999).'@example.com';
$ua=SDSC_Account::ensure($A,'A','A'); $uo=get_userdata($ua);
$c($cm->invoke(null,$uo),'a fresh automatic login can be claimed');
$reg=array('id'=>0,'class_id'=>0,'first_name'=>'A','last_name'=>'A','email'=>$A,'notes'=>'','registrant_id'=>0);
$c(true===$rm->invoke(null,$reg,$A,'a-long-test-password-1'),'password is set for the registration e-mail');
$c(wp_check_password('a-long-test-password-1',get_userdata($ua)->user_pass,$ua),'the new password works');
$c(true!==$rm->invoke(null,$reg,$A,'another-long-password-2'),'a second claim on the same login is refused (use Lost your password)');
// existing normal account must never be taken over
$ub=wp_insert_user(array('user_login'=>$B,'user_email'=>$B,'user_pass'=>wp_generate_password(20),'role'=>'subscriber'));
$c(!$cm->invoke(null,get_userdata($ub)),'an ordinary existing account (not made by registration) cannot be claimed');
$reg2=$reg; $reg2['email']=$B; $c(true!==$rm->invoke(null,$reg2,$B,'a-long-test-password-3'),'its password is not changed through this page');
// staff can never be claimed
$us=SDSC_Account::ensure($C,'C','C'); $x=new WP_User($us); $x->set_role('editor');
$c(!$cm->invoke(null,new WP_User($us)),'an account with staff rights cannot be claimed');
// corrected e-mail replaces the old one on the login
$D='sdsc-test-d'.wp_rand(1000,9999).'@example.com'; $E='sdsc-test-e'.wp_rand(1000,9999).'@example.com';
$ud=SDSC_Account::ensure($D,'D','D'); $reg3=array('id'=>0,'class_id'=>0,'first_name'=>'D','last_name'=>'D','email'=>$D,'notes'=>'','registrant_id'=>0);
$c(true===$rm->invoke(null,$reg3,$E,'a-long-test-password-4'),'corrected e-mail: password set');
$g=get_userdata($ud); $c($g->user_email===$E && $g->user_login===$E,'corrected e-mail replaced the old one on the login');
$c(false===get_user_by('email',$D),'the wrong e-mail no longer has a login');
$c(0===$mails,'none of this sent an e-mail');
foreach(array($ua,$ub,$us,$ud) as $i){ if($i){ wp_delete_user($i); } }
// the page itself
global $wpdb; $rid=(int)$wpdb->get_var('SELECT id FROM '.SDSC_Registration::table().' ORDER BY id DESC LIMIT 1');
if($rid){ $u=SDSC_Account::pw_url($rid); $r=wp_remote_get($u,array('timeout'=>20,'sslverify'=>false));
 if(!is_wp_error($r) && 200===wp_remote_retrieve_response_code($r)){ $b=wp_remote_retrieve_body($r); $c(false!==strpos($b,'type it yourself') && false!==strpos($b,'class="lock"'),'page shows the registered e-mail as non-copyable text and asks the student to type it'); $c(false===strpos($b,'name="pw1"'),'no password field before the e-mail is confirmed'); }
 else { echo "skip: page fetch (".(is_wp_error($r)?$r->get_error_message():wp_remote_retrieve_response_code($r)).")\n"; }
 $r=wp_remote_get(add_query_arg('t','bad',$u),array('timeout'=>20,'sslverify'=>false)); if(!is_wp_error($r)){ $c(false!==strpos(wp_remote_retrieve_body($r),'not valid'),'a wrong token shows "not valid"'); } }
echo "$ok ok, $bad failed\n";
