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
 array('I think I used the wrong email',0,'correct e-mail exactly'),
 array('I am an old student, I registered before',0,'most likely already have a login'),
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
// links at the end of answers and "My pages"
$r=SDSC_Helper::answer_id('locked',0,'en'); $c(false!==strpos($r['html'],'wp-login'),'visitor answer ends with a link to the login page');
$r=SDSC_Helper::answer_id('register',0,'fa'); $c(false!==strpos($r['html'],'student-registration'),'registration answer links to the registration page (Persian)');
$tu=wp_insert_user(array('user_login'=>'sdsc-bot-test'.wp_rand(1000,9999),'user_pass'=>wp_generate_password(20),'user_email'=>'sdsc-bot-test'.wp_rand(1000,9999).'@example.com','role'=>'subscriber'));
wp_set_current_user($tu);
$r=SDSC_Helper::answer_id('scorecard',1,'en'); $c(false!==strpos($r['html'],'sdsc-lessons') && false!==strpos($r['html'],'My driving lessons'),'student answer ends with a button to the lessons page');
$r=SDSC_Helper::answer_id('scorecard',1,'es'); $c(false!==strpos($r['html'],'Abrir: Mis clases'),'button text follows the language (Spanish)');
$pg=SDSC_Helper::my_pages('en'); $us=''; foreach($pg as $x){$us.=$x['u'].' ';}
$c(count($pg)>=3 && false!==strpos($us,'sdsc-lessons') && false!==strpos($us,'enrolled-courses'),'a logged-in student sees their dashboard sections: '.count($pg));
$c(false===strpos($us,'sdsc-reports') && false===strpos($us,'sdsc-work') && false===strpos($us,'zoom-attendance'),'staff pages are not offered to students');
$r=SDSC_Helper::rest_menu(new WP_REST_Request('POST','/sdsc/v1/helper/menu')); $c(!empty($r['pages']),'menu returns the pages for the logged-in student');
wp_set_current_user(0); wp_delete_user($tu);
$c(array()===SDSC_Helper::my_pages('en'),'visitors get no "My pages"');
// "My driving lessons" stays "being updated" for students until switched on
$ls=get_option(SDSC_Lessons::SET,null); $tu2=wp_insert_user(array('user_login'=>'sdsc-ls-test'.wp_rand(1000,9999),'user_pass'=>wp_generate_password(20),'user_email'=>'sdsc-ls-test'.wp_rand(1000,9999).'@example.com','role'=>'subscriber'));
$o=(array)$ls; $o['student_live']=0; update_option(SDSC_Lessons::SET,$o); wp_set_current_user($tu2);
$c(false!==strpos(SDSC_Lessons::shortcode(),'This section is being updated'),'student sees "being updated" while the lessons page is off');
$o['student_live']=1; update_option(SDSC_Lessons::SET,$o); $c(false===strpos(SDSC_Lessons::shortcode(),'This section is being updated'),'student sees the lessons once switched on');
$o['student_live']=0; update_option(SDSC_Lessons::SET,$o); wp_set_current_user(1); $c(false===strpos(SDSC_Lessons::shortcode(),'This section is being updated'),'office always sees the lessons page');
wp_set_current_user(0); wp_delete_user($tu2); if(null===$ls){delete_option(SDSC_Lessons::SET);}else{update_option(SDSC_Lessons::SET,$ls);}
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
$s=SDSC_Helper::suggest('blue banana airplane',0,'en'); $c(!$s['found'] && false!==strpos($s['html'],'sms:+12026000889?&amp;body=blue%20banana%20airplane'),'unknown question: tap-to-text number carries the question');
$r=SDSC_Helper::answer_id('account',0,'en'); $c(false!==strpos($r['html'],'Lost your password') && false!==strpos($r['html'],'created for you'),'account step: login is created automatically, student only sets a password');
$old=get_option(SDSC_Helper::OFF,null); update_option(SDSC_Helper::OFF,array('fees'));
$m=SDSC_Helper::questions(0,'en'); $has=false; foreach($m as $s){foreach($s['items'] as $i){if('latefee'===$i['id']){$has=true;}}} $c(!$has,'switched-off section is not listed for visitors');
$m=SDSC_Helper::questions(3,'en'); $has=false; foreach($m as $s){foreach($s['items'] as $i){if('latefee'===$i['id']){$has=true;}}} $c($has,'switched-off section is still listed for staff');
$r=SDSC_Helper::answer_id('latefee',0,'en'); $c(!$r['found'],'switched-off answer is refused by id');
if(null===$old){delete_option(SDSC_Helper::OFF);}else{update_option(SDSC_Helper::OFF,$old);} if(null!==$oc){update_option(SDSC_Helper::CFG,$oc);}
echo "$ok ok, $bad failed\n";
