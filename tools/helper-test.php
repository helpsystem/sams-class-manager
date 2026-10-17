<?php
function h_answer($q,$lv,$lang='en'){ $s=SDSC_Helper::suggest($q,$lv,$lang); if(empty($s['found'])){ return array('found'=>false,'held'=>!empty($s['held']),'html'=>$s['html']); } $r=SDSC_Helper::answer_id($s['suggest'][0]['id'],$lv,$lang); return array('found'=>true,'held'=>false,'html'=>$r['html']); }
$ok=0;$bad=0;$c=function($b,$m)use(&$ok,&$bad){echo ($b?'ok ':'FAIL ').$m."\n";$b?$ok++:$bad++;};
$ask=function($q,$lv=0){ $a=h_answer($q,$lv); return $a; };
$cases=array(
 array('I did not get the Zoom link',0,'separate e-mail before the first session'),
 array('where is my zoom passcode',0,'separate e-mail before the first session'),
 array('I cannot log in',0,'verification link'),
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
$a=h_answer('what does the workspace menu do',3); echo 'staff tour: '.($a['found']?'found':'none')."\n";

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
foreach($tr as $x){ $a=h_answer($x[1],$x[2],$x[0]); $c($a['found'] && false!==strpos($a['html'],$x[3]),$x[0].': '.$x[1]); }
$a=h_answer('blue banana airplane',0,'es'); $c(!$a['found'] && false!==strpos($a['html'],'tel:'),'es: no guess gives the hand-off message with a call link');
// Switching a section off
$old=get_option(SDSC_Helper::OFF,null); $oc=get_option(SDSC_Helper::CFG,null);
update_option(SDSC_Helper::CFG,array('call'=>'(301) 726-3030','text'=>'(202) 555-0100','msg'=>array()));
update_option(SDSC_Helper::OFF,array('fees'));
$a=h_answer('how much is the late cancellation fee',0,'en');
$c(!$a['found'] && !empty($a['held']) && false===strpos($a['html'],'$60'),'switched-off section is not answered for visitors');
$c(false!==strpos($a['html'],'tel:+13017263030') && false!==strpos($a['html'],'sms:+12025550100'),'hand-off has a call link to the office and a text link to the other number');
$a=h_answer('how much is the late cancellation fee',1,'fa'); $c(!$a['found'] || false===strpos($a['html'],'$60x'),'persian still answers or hands off');
$a=h_answer('I am running late and sick',2,'en'); $c($a['found'],'instructor section still answers');
update_option(SDSC_Helper::CFG,array('call'=>'(301) 726-3030','text'=>'','msg'=>array()));
$a=h_answer('blue banana airplane',0,'en'); $c(false!==strpos($a['html'],'sms:+13017263030'),'empty text number falls back to the office phone');
if(null===$old){delete_option(SDSC_Helper::OFF);}else{update_option(SDSC_Helper::OFF,$old);} if(null===$oc){delete_option(SDSC_Helper::CFG);}else{update_option(SDSC_Helper::CFG,$oc);}
$a=h_answer('how much is the late cancellation fee',0,'en'); $c($a['found'],'section answers again after switching back on');

$s=SDSC_Helper::suggest('the website sent me a verification email to login',0,'en'); $ids2=array_column($s['suggest']??array(),'id'); $c(in_array('login',$ids2,true),'verification e-mail question suggests the login answer');
// Prepared-question menu, hand-off with the question, account step
$m=SDSC_Helper::questions(0,'en'); $ids=array(); foreach($m as $s){foreach($s['items'] as $i){$ids[]=$i['id'];}}
$c(in_array('register',$ids,true) && !in_array('late',$ids,true),'menu lists visitor questions and hides instructor ones');
$m3=SDSC_Helper::questions(2,'es'); $c(count($m3)>0 && !empty($m3[0]['title']),'menu works in Spanish for instructors');
$r=SDSC_Helper::answer_id('latefee',0,'fa'); $c($r['found'] && false!==strpos($r['html'],'$60'),'answer by id (Persian)');
$r=SDSC_Helper::answer_id('nope',0,'en'); $c(!$r['found'] && false!==strpos($r['html'],'tel:'),'unknown id hands off');
$oc=get_option(SDSC_Helper::CFG,null); delete_option(SDSC_Helper::CFG);
$s=SDSC_Helper::suggest('blue banana airplane',0,'en'); $c(!$s['found'] && false!==strpos($s['html'],'sms:+12026000889?&body=blue%20banana%20airplane'),'unknown question: tap-to-text number carries the question');
$r=SDSC_Helper::answer_id('account',0,'en'); $c(false!==strpos($r['html'],'student-registration') && false!==strpos($r['html'],'same e-mail'),'account step points to the Student Registration page');
$old=get_option(SDSC_Helper::OFF,null); update_option(SDSC_Helper::OFF,array('fees'));
$m=SDSC_Helper::questions(0,'en'); $has=false; foreach($m as $s){foreach($s['items'] as $i){if('latefee'===$i['id']){$has=true;}}} $c(!$has,'switched-off section is not listed for visitors');
$m=SDSC_Helper::questions(3,'en'); $has=false; foreach($m as $s){foreach($s['items'] as $i){if('latefee'===$i['id']){$has=true;}}} $c($has,'switched-off section is still listed for staff');
$r=SDSC_Helper::answer_id('latefee',0,'en'); $c(!$r['found'],'switched-off answer is refused by id');
if(null===$old){delete_option(SDSC_Helper::OFF);}else{update_option(SDSC_Helper::OFF,$old);} if(null!==$oc){update_option(SDSC_Helper::CFG,$oc);}
echo "$ok ok, $bad failed\n";
