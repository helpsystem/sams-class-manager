<?php
global $wpdb;
$ok=0;$bad=0;$c=function($b,$m)use(&$ok,&$bad){echo ($b?'ok ':'FAIL ').$m."\n";$b?$ok++:$bad++;};
$adm=get_users(array('role'=>'administrator','number'=>1)); wp_set_current_user($adm[0]->ID);
add_filter('wp_doing_ajax','__return_true');
add_filter('wp_die_ajax_handler',function(){return function(){throw new Exception('die');};});
$call=function($post){ $_POST=$post; $_REQUEST=$post; ob_start(); try{ SDSC_DFBook::ajax_board(); }catch(Exception $e){} $o=ob_get_clean(); return json_decode($o,true); };
$day=wp_date('Y-m-d',strtotime('+3 days'));
$cars=$wpdb->get_results("SELECT id,plate_number FROM {$wpdb->prefix}driveflow_vehicles WHERE status='active' AND plate_number NOT LIKE 'DEMO%' ORDER BY plate_number",ARRAY_A);
$c(count($cars)>=1,'cars on staging: '.count($cars));
$r=$call(array('_n'=>wp_create_nonce('sdsc_dfb'),'date'=>$day,'minutes'=>120));
$c(!empty($r['success']),'board answers');
$d=$r['data']; $c(count($d['rows'])>=5,'rows: '.count($d['rows'])); $c(count($d['cars'])===count($cars),'one column per car');
$free0=0; foreach($d['rows'] as $row){ foreach($row['cars'] as $x){ if($x['st']===0){$free0++;} } }
$c($free0>0,'some free cells: '.$free0);
// book one lesson in the first car at 11:00 and look again
$T=$wpdb->prefix.'driveflow_sessions'; $car=$cars[0];
$wpdb->insert($T,array('student_name'=>'Board Test','student_id'=>0,'instructor_name'=>'Nobody Test','instructor_id'=>0,'vehicle_id'=>(int)$car['id'],'plate_number'=>$car['plate_number'],'session_number'=>1,'lesson_topic'=>'t','scheduled_start'=>$day.' 11:00:00','scheduled_end'=>$day.' 13:00:00','status'=>'upcoming','form_data'=>'{}','signature'=>'','selfie'=>'','created_by'=>0,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
$sid=$wpdb->insert_id;
$r=$call(array('_n'=>wp_create_nonce('sdsc_dfb'),'date'=>$day,'minutes'=>120)); $d=$r['data'];
$row=null; foreach($d['rows'] as $x){ if($x['t']==='11:00'){$row=$x;} }
$c($row && $row['cars'][(string)$car['id']]['st']===1,'busy car shows as busy at 11:00');
$row2=null; foreach($d['rows'] as $x){ if($x['t']==='13:00'){$row2=$x;} }
$c($row2 && $row2['cars'][(string)$car['id']]['st']===0,'same car free again at 13:00');
$row3=null; foreach($d['rows'] as $x){ if($x['t']==='09:00'){$row3=$x;} }
$c($row3 && $row3['cars'][(string)$car['id']]['st']===0,'same car free at 9:00 (ends 11:00)');
$wpdb->delete($T,array('id'=>$sid));
echo "$ok ok, $bad failed\n";
