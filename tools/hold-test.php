<?php
$ok=0;$bad=0;$c=function($b,$m)use(&$ok,&$bad){echo ($b?'ok ':'FAIL ').$m."\n";$b?$ok++:$bad++;};
update_option(SDSC_Hold::OPT,'on'); delete_option(SDSC_Hold::LOG);
$c(false===SDSC_Hold::gate_mail(null,array('to'=>'kid@example.test','subject'=>'x','message'=>'m')),'student mail blocked');
$c(false===SDSC_Hold::gate_mail(null,array('to'=>array('a@example.test','b@example.test'),'subject'=>'x','message'=>'m')),'several outside addresses blocked');
$c(null===SDSC_Hold::gate_mail(null,array('to'=>'info@samsdrivingschool.org','subject'=>'x','message'=>'m')),'school address passes');
$c(true===SDSC_Hold::block_sms('+13015550000','hi'),'text blocked');
$c(count(get_option(SDSC_Hold::LOG))>=4,'held items logged');
$c(null===SDSC_Hold::gate_mail(null,array('to'=>'kid@example.test','subject'=>'Login Verification Required','message'=>'m')),'login code is never held');
$c(null===SDSC_Hold::gate_mail(null,array('to'=>'kid@example.test','subject'=>"[Sam's Driving School] Password Reset",'message'=>'m')),'password reset is never held');
update_option(SDSC_Hold::OPT,'off');
$c(null===SDSC_Hold::gate_mail(null,array('to'=>'kid@example.test','subject'=>'x','message'=>'m')),'passes when hold is off');
$c(false===SDSC_Hold::block_sms('+13015550000','hi'),'text passes when off');
update_option(SDSC_Hold::OPT,'on'); delete_option(SDSC_Hold::LOG);
echo "$ok ok, $bad failed\n";
