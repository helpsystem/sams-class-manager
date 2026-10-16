<?php
// Staging check for SDSC_Docs::store (instructor onboarding uploads). Removes everything it creates.
global $wpdb; $ok=0; $bad=0;
$t = function($c,$m) use (&$ok,&$bad){ if($c){$ok++;echo "ok  $m\n";} else {$bad++;echo "BAD $m\n";} };
$em = 'onb-test-' . wp_generate_password(6,false) . '@example.test';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$r = new ReflectionClass('SDSC_Docs');
$td = $r->getMethod('t_docs'); $td->setAccessible(true); $T = $td->invoke(null);
$tl = $r->getMethod('t_log');  $tl->setAccessible(true); $L = $tl->invoke(null);
$dir= $r->getMethod('dir');    $dir->setAccessible(true); $D = $dir->invoke(null);
$get= $r->getMethod('get');    $get->setAccessible(true);
$id = SDSC_Docs::store($em, $png, 'ins_dl_front', 'Driver licence (onboarding link)', 'student', 'Test Person');
$t($id > 0, "store returns id ($id)");
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id=%d",$id), ARRAY_A);
$t($row && $row['status']==='new' && $row['type']==='ins_dl_front' && $row['source']==='student' && $row['mime']==='image/png', 'row: new / ins_dl_front / student / png');
$t($row && is_file($D.$row['file']) && file_get_contents($D.$row['file']) !== $png, 'file on disk is encrypted');
$t($row && $get->invoke(null,$row['file']) === $png, 'decrypts back to the same picture');
$t(strpos(realpath($D), realpath(ABSPATH)) !== 0, 'store dir is outside the web folder: '.$D);
$t(0 === SDSC_Docs::store($em, '<html><script>alert(1)</script></html>', 'ins_cert'), 'HTML rejected');
$t(0 === SDSC_Docs::store($em, '', 'ins_cert'), 'empty rejected');
$t(0 === SDSC_Docs::store('not-an-email', $png, 'ins_cert'), 'bad email rejected');
$u = SDSC_Docs::url($em); $t($u && strpos($u, '.bin')===false, 'url() is not a file link: '.$u);
// clean up
$rows = $wpdb->get_results($wpdb->prepare("SELECT id,file FROM $T WHERE email=%s",$em), ARRAY_A);
foreach($rows as $x){ @unlink($D.$x['file']); $wpdb->delete($L,array('doc_id'=>$x['id'])); $wpdb->delete($T,array('id'=>$x['id'])); }
$t(0 == $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE email=%s",$em)), 'test rows removed');
echo "PASS $ok FAIL $bad\n";
