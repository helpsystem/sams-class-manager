<?php
$ok=0;$bad=0;$c=function($b,$m)use(&$ok,&$bad){echo ($b?'ok ':'FAIL ').$m."\n";$b?$ok++:$bad++;};
$sent=array(); add_filter('pre_wp_mail',function($p,$a)use(&$sent){$sent[]=$a;return true;},5,2);
$old=get_option(SDSC_TwoFactor::OPT,null); delete_option(SDSC_TwoFactor::OPT);
$code=function()use(&$sent){ $m=end($sent); return $m?(preg_match('/letter-spacing:8px[^>]*>(\d{6})</',$m['message'],$x)?$x[1]:''):''; };
$mk=function($role){ $e='sdsc-2fa-'.wp_rand(10000,99999).'@example.com'; $id=wp_insert_user(array('user_login'=>$e,'user_email'=>$e,'user_pass'=>wp_generate_password(24),'role'=>$role)); return get_userdata($id); };
$ins=$mk('tutor_instructor'); $stu=$mk('subscriber'); $adm=$mk('administrator');
$c(SDSC_TwoFactor::covered($ins),'an instructor must use a code');
$c(!SDSC_TwoFactor::covered($stu),'a student does not');
$c(!SDSC_TwoFactor::covered($adm),'an administrator is not in the instructor roles');
$c($stu===SDSC_TwoFactor::gate($stu,'x','y'),'gate lets a non-covered person straight through');
// code is mailed, even to an outside address, while the Notifications hold is ON
$hold=get_option(SDSC_Hold::OPT,null); update_option(SDSC_Hold::OPT,'on');
$k=SDSC_TwoFactor::begin($ins,true,'/dash/'); $c(is_string($k) && 32===strlen($k),'a sign-in starts and returns a key');
$c(1===count($sent) && $sent[0]['to']===$ins->user_email || (is_array($sent[0]['to']) && in_array($ins->user_email,(array)$sent[0]['to'])),'one e-mail goes to the instructor (not held)');
$c(false!==strpos($sent[0]['subject'],'login verification code'),'subject says "login verification code"');
$cd=$code(); $c(6===strlen($cd),'the e-mail holds a 6-digit code');
// wrong codes
$wrong=('000000'===$cd)?'111111':'000000';
$r=SDSC_TwoFactor::verify($k,$wrong); $c(is_wp_error($r) && false!==strpos($r->get_error_message(),'4 tries left'),'a wrong code is refused and counted');
$r=SDSC_TwoFactor::verify($k,$cd); $c(is_array($r) && $r['uid']===$ins->ID && true===$r['remember'] && '/dash/'===$r['redirect'],'the right code works and keeps remember-me and the destination');
$r=SDSC_TwoFactor::verify($k,$cd); $c(is_wp_error($r),'a code works only once');
// lock after 5 wrong
$k=SDSC_TwoFactor::begin($ins); $cd=$code(); for($i=0;$i<5;$i++){ $r=SDSC_TwoFactor::verify($k,$wrong); }
$c(is_wp_error($r) && false!==strpos($r->get_error_message(),'Too many'),'5 wrong codes stop the sign-in');
$r=SDSC_TwoFactor::verify($k,$cd); $c(is_wp_error($r),'then even the right code no longer works');
// resend rules
$k=SDSC_TwoFactor::begin($ins); $r=SDSC_TwoFactor::resend($k); $c(is_wp_error($r) && false!==strpos($r->get_error_message(),'wait a minute'),'a new code cannot be asked for within a minute');
// switches
$o=SDSC_TwoFactor::settings(); $o['skip']=array($ins->ID); update_option(SDSC_TwoFactor::OPT,$o); $c(!SDSC_TwoFactor::covered($ins),'office can skip one person');
$o['skip']=array(); $o['on']=0; update_option(SDSC_TwoFactor::OPT,$o); $c(!SDSC_TwoFactor::covered($ins),'the master switch turns it off');
$o['on']=1; update_option(SDSC_TwoFactor::OPT,$o); $c(SDSC_TwoFactor::covered($ins),'and on again');
// ajax / app logins cannot slip past
add_filter('wp_doing_ajax','__return_true'); $r=SDSC_TwoFactor::gate($ins,'x','y'); remove_filter('wp_doing_ajax','__return_true');
$c(is_wp_error($r),'a login through an AJAX form is refused with a pointer to the main login page');
// remembered device (7 days)
$c(7===(int)SDSC_TwoFactor::settings()['days'],'a device is remembered 7 days by default');
$exp=time()+7*DAY_IN_SECONDS; $tok=SDSC_TwoFactor::trust_token($ins2=$mk('tutor_instructor'),$exp);
$c(SDSC_TwoFactor::is_trusted($ins2,$tok),'a fresh remembered-device token is accepted');
$c(!SDSC_TwoFactor::is_trusted($ins2,SDSC_TwoFactor::trust_token($ins2,time()-10)),'an expired token is refused');
$c(!SDSC_TwoFactor::is_trusted($ins2,$tok.'x'),'a tampered token is refused');
$other=$mk('tutor_instructor'); $c(!SDSC_TwoFactor::is_trusted($other,$tok),'a token does not work for another person');
$_COOKIE['sdsc2fa_t']=$tok; $n0=count($sent); $r=SDSC_TwoFactor::gate($ins2,'x','y'); $c($r===$ins2 && count($sent)===$n0,'a remembered device signs in without a new code or e-mail'); unset($_COOKIE['sdsc2fa_t']);
wp_set_password('a-brand-new-password-1',$ins2->ID); $ins2=get_userdata($ins2->ID); $c(!SDSC_TwoFactor::is_trusted($ins2,$tok),'changing the password ends the remembered device');
$tok=SDSC_TwoFactor::trust_token($ins2,$exp); $ep=get_option('sdsc_2fa_epoch',null); update_option('sdsc_2fa_epoch',time()+5,false); $c(!SDSC_TwoFactor::is_trusted($ins2,$tok),'"forget all devices" ends every remembered device'); if(null===$ep){delete_option('sdsc_2fa_epoch');}else{update_option('sdsc_2fa_epoch',$ep,false);}
wp_delete_user($ins2->ID); wp_delete_user($other->ID);
foreach(array($ins,$stu,$adm) as $u){ wp_delete_user($u->ID); }
if(null===$old){delete_option(SDSC_TwoFactor::OPT);}else{update_option(SDSC_TwoFactor::OPT,$old);} if(null===$hold){delete_option(SDSC_Hold::OPT);}else{update_option(SDSC_Hold::OPT,$hold);}
echo "$ok ok, $bad failed\n";
