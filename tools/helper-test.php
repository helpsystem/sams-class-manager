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
 array('how much is the late cancellation fee',0,'$60'),
 array('how long do I have to use my extra practice',0,'6 months'),
 array('can I get a refund on the car rental for my road test',0,'full refund'),
 array('what courses do you have',0,'36-hour Driver'),
);
foreach($cases as $x){ $a=$ask($x[0],$x[1]); $c($a['found'] && false!==strpos($a['html'],$x[2]),'"'.$x[0].'" -> '.($a['found']?substr(strip_tags($a['html']),0,40):'NOT FOUND')); }
foreach(array('what is the meaning of life','blue banana airplane','best pizza in town') as $q){ $a=$ask($q,0); $c(!$a['found'],'no guess: '.$q); }
$a=$ask('how do I cancel a lesson',0); $c(!$a['found']||false===strpos($a['html'],'free until the deadline'),'student-level answer not shown to the public');
$a=SDSC_Helper::answer('what does the workspace menu do',3); echo 'staff tour: '.($a['found']?'found':'none')."\n";
echo "$ok ok, $bad failed\n";
