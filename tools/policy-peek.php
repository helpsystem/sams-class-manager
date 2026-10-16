<?php
$r=new ReflectionMethod('SDSC_Helper','policy_sections'); $r->setAccessible(true);
foreach($r->invoke(null) as $x){ echo '['.$x['t'].'] '.mb_substr(strip_tags($x['a']),0,110)."\n"; }
echo count($r->invoke(null))." sections\n";
