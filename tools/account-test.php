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
echo "$ok ok, $bad failed\n";
