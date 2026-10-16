<?php
$ok=0;$bad=0;$c=function($b,$m)use(&$ok,&$bad){echo ($b?'ok ':'FAIL ').$m."\n";$b?$ok++:$bad++;};
$ask=function($q,$lv=0){ $a=SDSC_Helper::answer($q,$lv); return $a; };
$cases=array(
 array('I did not get the Zoom link',0,'separate e-mail before the first session'),
 array('where is my zoom passcode',0,'separate e-mail before the first session'),
 array('I cannot log in',0,'verification link'),
 array('the website sent me a verification email to login',0,'verification link'),
 array('I forgot my password',0,'Lost your password'),
 array('how do I cancel a lesson',1,'free until the deadline'),
 array('can I pay with a check',0,'cash, check or Zelle'),
 array('the quiz is locked',1,'open after your payment'),
 array('how many classes can I miss',1,'4 absences'),
 array('I am sick and cannot drive today',2,'Call the office'),
 array('How do I register?',0,'See class dates'),
 array('I registered but cannot log in',0,'same e-mail you registered with'),
 array('I think I used the wrong email',0,'check and fix'),
 array('how much is the late cancellation fee',0,'$60'),
 array('how long do I have to use my extra practice',0,'6 months'),
 array('can I get a refund on the car rental for my road test',0,'full refund'),
 array('what courses do you have',0,'36-hour Driver'),
);
foreach($cases as $x){ $a=$ask($x[0],$x[1]); $c($a['found'] && false!==strpos($a['html'],$x[2]),'"'.$x[0].'" -> '.($a['found']?substr(strip_tags($a['html']),0,40):'NOT FOUND')); }
foreach(array('what is the meaning of life','blue banana airplane','best pizza in town') as $q){ $a=$ask($q,0); $c(!$a['found'],'no guess: '.$q); }
$a=$ask('how do I cancel a lesson',0); $c(!$a['found']||false===strpos($a['html'],'free until the deadline'),'student-level answer not shown to the public');
$a=SDSC_Helper::answer('what does the workspace menu do',3); echo 'staff tour: '.($a['found']?'found':'none')."\n";

// Spanish and Persian
$tr=array(
 array('es','No recibí el enlace de Zoom',0,'correo aparte'),
 array('es','no puedo iniciar sesión',0,'enlace de verificación'),
 array('es','¿cuánto es la tarifa por cancelación tardía?',0,'$60'),
 array('es','¿cómo me registro?',0,'See class dates'),
 array('fa','لینک زوم به دستم نرسید',0,'ایمیل جداگانه'),
 array('fa','ثبت نام کردم ولی نمی‌توانم وارد شوم',0,'همان ایمیلی'),
 array('fa','جریمه لغو دیرهنگام چقدر است',0,'$60'),
);
foreach($tr as $x){ $a=SDSC_Helper::answer($x[1],$x[2],$x[0]); $c($a['found'] && false!==strpos($a['html'],$x[3]),$x[0].': '.$x[1]); }
$a=SDSC_Helper::answer('blue banana airplane',0,'es'); $c(!$a['found'] && false!==strpos($a['html'],'tel:'),'es: no guess gives the hand-off message with a call link');
// Switching a section off
$old=get_option(SDSC_Helper::OFF,null); $oc=get_option(SDSC_Helper::CFG,null);
update_option(SDSC_Helper::CFG,array('call'=>'(301) 726-3030','text'=>'(202) 555-0100','msg'=>array()));
update_option(SDSC_Helper::OFF,array('fees'));
$a=SDSC_Helper::answer('how much is the late cancellation fee',0,'en');
$c(!$a['found'] && !empty($a['held']) && false===strpos($a['html'],'$60'),'switched-off section is not answered for visitors');
$c(false!==strpos($a['html'],'tel:+13017263030') && false!==strpos($a['html'],'sms:+12025550100'),'hand-off has a call link to the office and a text link to the other number');
$a=SDSC_Helper::answer('how much is the late cancellation fee',1,'fa'); $c(!$a['found'] || false===strpos($a['html'],'$60x'),'persian still answers or hands off');
$a=SDSC_Helper::answer('I am running late and sick',2,'en'); $c($a['found'],'instructor section still answers');
update_option(SDSC_Helper::CFG,array('call'=>'(301) 726-3030','text'=>'','msg'=>array()));
$a=SDSC_Helper::answer('blue banana airplane',0,'en'); $c(false!==strpos($a['html'],'sms:+13017263030'),'empty text number falls back to the office phone');
if(null===$old){delete_option(SDSC_Helper::OFF);}else{update_option(SDSC_Helper::OFF,$old);} if(null===$oc){delete_option(SDSC_Helper::CFG);}else{update_option(SDSC_Helper::CFG,$oc);}
$a=SDSC_Helper::answer('how much is the late cancellation fee',0,'en'); $c($a['found'],'section answers again after switching back on');
echo "$ok ok, $bad failed\n";
