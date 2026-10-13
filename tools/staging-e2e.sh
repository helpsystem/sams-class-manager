#!/bin/bash
# Staging end-to-end check of the public site and the registration flow.
# No real e-mail: test people use @example.com and the name "E2E-Test"; every e-mail for them
# (and the office notice about them) is logged to /tmp/sdsc-e2e-mail.log instead of being sent.
# Test registrations are removed at the end. Run as root on the server: bash staging-e2e.sh
ST=/home/testsaman/htdocs/testsaman.samsdrivingschool.org; SITE=https://testsaman.samsdrivingschool.org
W="wp --allow-root --path=$ST"; LOG=/tmp/sdsc-e2e-mail.log; : > $LOG; chmod 666 $LOG
MU=$ST/wp-content/mu-plugins; mkdir -p $MU
cat > $MU/zz-e2e-mail.php <<'PHP'
<?php // TEMPORARY (end-to-end test): e-mail about E2E-Test people is logged, never sent.
add_filter('pre_wp_mail',function($r,$a){ $to=is_array($a['to'])?implode(',',$a['to']):$a['to']; $m=is_string($a['message'])?$a['message']:'';
 if(false===stripos($to,'@example.com') && false===stripos($m,'E2E-Test') && false===stripos($a['subject'],'E2E-Test')) return $r;
 file_put_contents('/tmp/sdsc-e2e-mail.log',$to.' | '.$a['subject']."\n",FILE_APPEND); return true; },1,2);
PHP
chown testsaman:testsaman $MU/zz-e2e-mail.php
P=0; F=0
chk(){ if eval "$2"; then echo "PASS  $1"; P=$((P+1)); else echo "FAIL  $1"; F=$((F+1)); fi; }
q(){ $W eval "$1" 2>/dev/null; }
PNG='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
echo "== Homepage"
H=$(curl -s "$SITE/?e2e=$RANDOM")
chk "hero: Approved by MVA + title" '[[ "$H" == *"Approved by MVA"* && "$H" == *"Affordable driving school"* ]]'
chk "hero Enroll now -> #driver-ed" '[[ "$H" == *"mdh-btn--go\" href=\"$SITE/#driver-ed\""* ]]'
o(){ echo "$H" | grep -bo "$1" | head -1 | cut -d: -f1; }
a=$(o 'id="driver-ed"'); b=$(o 'id="practice"'); c=$(o 'id="services"')
chk "order: 36-hour < practice < Our services" '[[ -n "$a" && -n "$b" && -n "$c" && $a -lt $b && $b -lt $c ]]'
chk "practice shown as signs" '[[ "$H" == *"sdsc3d-item--btw"* && "$H" != *"sdsc3d-btw-t"* ]]'
chk "online + road-test car inside Our services" '[[ $(o "id=\"online-course\"") -gt $c && $(o "id=\"road-test-car\"") -gt $c ]]'
chk "Driver Improvement once, no Drug & Alcohol card" '[[ $(echo "$H" | grep -o ">Driver Improvement<" | wc -l) -eq 1 && "$H" != *">Drug &amp; Alcohol<"* ]]'
chk "no old 'Road test car & online course' section" '[[ "$H" != *"Road test car &amp; online course"* ]]'
chk "jump bar + All services link" '[[ "$H" == *"data-sds-jump"* && "$H" == *"All services"* ]]'
chk "one logo in header (no top mark)" '[[ "$H" != *"sdh-top-mark"* ]]'
chk "SEO title with Rockville" '[[ "$H" == *"<title>Affordable Driving School in Rockville, MD"* ]]'
chk "one meta description" '[[ $(echo "$H" | grep -o "name=\"description\"" | wc -l) -eq 1 ]]'
chk "local business data with hours" '[[ "$H" == *"\"DrivingSchool\",\"LocalBusiness\""* && "$H" == *"openingHoursSpecification"* ]]'
chk "one H1" '[[ $(echo "$H" | grep -o "<h1" | wc -l) -eq 1 ]]'
chk "credit links to abyarsaman.com" '[[ "$H" == *"https://abyarsaman.com/"* ]]'
chk "quizzes out of sitemap" '[[ $(curl -s "$SITE/sitemap_index.xml?e2e=$RANDOM" | grep -c tutor_quiz) -eq 0 ]]'
echo "== Contact page"
C=$(curl -s "$SITE/contact-us/?e2e=$RANDOM")
chk "map pin on street address" '[[ "$C" == *"maps?q=751%20Rockville%20Pike%2C%20Rockville%2C%20MD%2020852"* ]]'
chk "office hours: all 7 days" '[[ $(echo "$C" | grep -o "<tr data-day=" | wc -l) -eq 7 ]]'
chk "phones clickable" '[[ "$C" == *"tel:+13017263030"* && "$C" == *"tel:+12026000889"* ]]'
chk "info@ e-mail first, then Gmail" '[[ $(echo "$C" | grep -bo "mailto:info@samsdrivingschool.org" | head -1 | cut -d: -f1) -lt $(echo "$C" | grep -bo "mailto:samdrivingschool.rockville@gmail.com" | head -1 | cut -d: -f1) ]]'
chk "address opens directions chooser" '[[ "$C" == *"data-sdsc-nav"* ]]'
echo "== Registration form"
CID=$(q 'foreach(get_posts(array("post_type"=>SDSC_CPT_Classes::CPT,"meta_key"=>"_sdsc_course_code","meta_value"=>"TEST-1","post_status"=>"any")) as $p) echo $p->ID;')
CODE=$(q "echo SDSC_Registration::public_code($CID);")
R=$(curl -s "$SITE/register-course/?course=$CODE&e2e=$RANDOM")
chk "class picked on homepage shown alone" '[[ "$R" == *"Your class"* && "$R" == *"data-other=\"1\""* && "$R" == *"Choose a different class"* ]]'
chk "Pay is step 6, submit says go to payment" '[[ "$R" == *"sdsc-step-pay"* && "$R" == *"go to payment"* ]]'
chk "parent email + policies box on form" '[[ "$R" == *"name=\"parent_email\""* && "$R" == *"name=\"parent_consent\""* ]]'
sub(){ # $1 tag  $2 dob  $3 extra args
 curl -s -o /dev/null -w '%{redirect_url}' -X POST "$SITE/wp-admin/admin-post.php" -H "Referer: $SITE/register-course/" \
 --data-urlencode action=sdsc_register --data-urlencode sdsc_return=$SITE/register-course/ --data-urlencode sdsc_js=ok --data-urlencode class_id=$CID \
 --data-urlencode first_name=E2E-Test --data-urlencode last_name="$1" --data-urlencode dob="$2" --data-urlencode email="e2e-$1@example.com" --data-urlencode phone=3015550111 \
 --data-urlencode street='1 Test St' --data-urlencode city=Rockville --data-urlencode zip=20852 --data-urlencode consent=1 --data-urlencode student_sig="$PNG" $3; }
U1=$(sub teen "$(date -d '-17 years' +%F)")
chk "17, no parent: accepted -> payment step" '[[ "$U1" == *"sdsc_reg=done"* ]]'
S1=$(curl -s "$U1")
chk "after submit: 'Forms done — last step: payment'" '[[ "$S1" == *"last step: payment"* && "$S1" == *"Continue to payment"* ]]'
chk "after submit: 'Is a parent paying?' box" '[[ "$S1" == *"Send payment link to my parent"* ]]'
U2=$(sub adult "$(date -d '-19 years' +%F)")
U3=$(sub young "$(date -d '-15 years' +%F)" "--data-urlencode parent_email=e2e-young-mom@example.com")
chk "19 and 15 accepted too" '[[ "$U2" == *"sdsc_reg=done"* && "$U3" == *"sdsc_reg=done"* ]]'
id(){ q "global \$wpdb; echo \$wpdb->get_var(\"SELECT id FROM \".SDSC_Registration::table().\" WHERE email='e2e-$1@example.com' ORDER BY id DESC LIMIT 1\");"; }
I1=$(id teen); I2=$(id adult); I3=$(id young)
B1=$(q "echo strip_tags(SDSC_Followup::badges($I1));"); B2=$(q "echo strip_tags(SDSC_Followup::badges($I2));"); B3=$(q "echo strip_tags(SDSC_Followup::badges($I3));")
echo "      17: $B1 | 19: $B2 | 15: $B3"
chk "17 flagged: parent part missing + not paid" '[[ "$B1" == *"Parent:"* && "$B1" == *"Not paid"* ]]'
chk "19 flagged: only not paid" '[[ "$B2" != *"Parent"* && "$B2" == *"Not paid"* ]]'
chk "15 flagged: under 16 — call" '[[ "$B3" == *"Under 16"* ]]'
sleep 2
chk "first reminder e-mail went out (17: student)" 'grep -q "e2e-teen@example.com | Finish E2E-Test teen" $LOG'
chk "first reminder e-mail to parent (15)" 'grep -q "e2e-young-mom@example.com | Finish" $LOG'
chk "no reminder for 19 (nothing but payment)" '! grep -q "e2e-adult@example.com | Finish" $LOG'
SINCE=$(q 'echo get_option("sdsc_followup_since");'); q 'update_option("sdsc_followup_since", wp_date("Y-m-d H:i:s", time()-6*HOUR_IN_SECONDS), false);'
q "global \$wpdb; \$wpdb->query(\"UPDATE \".SDSC_Registration::table().\" SET created_at = DATE_SUB(created_at, INTERVAL 3 HOUR) WHERE email LIKE 'e2e-%@example.com'\");"
TQ=$(q 'echo count(array_filter(SDSC_TextQ::items(),function($i){return $i["group"]==="registrations" && false!==strpos($i["name"],"E2E-Test");}));')
chk "texts for Saghar on 'Texts to send' ($TQ cards)" '[[ "$TQ" -ge 2 ]]'
q "echo '      sample text: '; \$i=array_values(array_filter(SDSC_TextQ::items(),function(\$i){return \$i['group']==='registrations';})); echo \$i?substr(\$i[0]['texts']['en'],0,150):'-', \"\n\";"
q "update_option('sdsc_followup_since', '$SINCE', false);"
ADM=$(q "\$a=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID')); wp_set_current_user((int)\$a[0]); \$_GET['view']=$I1; ob_start(); SDSC_Registration::render_admin(); echo ob_get_clean();")
chk "Registrations: Follow-up panel + reminder button" '[[ "$ADM" == *"Follow-up"* && "$ADM" == *"E-mail a reminder now"* ]]' || echo "      admin output: $(echo "$ADM" | tr -s ' \n' ' ' | cut -c1-240)"
echo "== Parent link"
PU=$(q "echo SDSC_Followup::parent_url($I1);"); PT=$(q "echo SDSC_Followup::token($I1);")
PG=$(curl -s "$PU&e2e=$RANDOM")
chk "parent page opens with form" '[[ "$PG" == *"Parent / guardian full name"* && "$PG" == *"Sign &amp; finish"* ]]'
chk "wrong token refused" '[[ $(curl -s "$SITE/register-course/?sdsc_parent=$I1&t=bad") == *"not valid"* ]]'
X=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$SITE/wp-admin/admin-post.php" -H "Referer: $PU" --data-urlencode action=sdsc_parent_save --data-urlencode r=$I1 --data-urlencode t=$PT --data-urlencode parent_name='E2E-Test Parent')
chk "incomplete parent form sent back" '[[ "$X" == *"sdsc_err=1"* ]]'
X=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$SITE/wp-admin/admin-post.php" -H "Referer: $PU" --data-urlencode action=sdsc_parent_save --data-urlencode r=$I1 --data-urlencode t=$PT --data-urlencode parent_name='E2E-Test Parent' --data-urlencode parent_phone=3015550122 --data-urlencode parent_email=e2e-parent@example.com --data-urlencode parent_consent=1 --data-urlencode parent_sig="$PNG")
B1=$(q "echo strip_tags(SDSC_Followup::badges($I1));")
chk "parent signed -> only 'Not paid' left ($B1)" '[[ "$X" == *"sdsc_done=1"* && "$B1" != *"Parent"* && "$B1" == *"Not paid"* ]]'
echo "== Payment"
RT=$(q "echo SDSC_Pay::token($I1);")
X=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$SITE/wp-admin/admin-post.php" -H "Referer: $U1" --data-urlencode action=sdsc_pay_parent --data-urlencode r=$I1 --data-urlencode t=$RT --data-urlencode c=$CID --data-urlencode parent_email=e2e-parent@example.com)
sleep 1
chk "payment link sent to parent" '[[ "$X" == *"sdsc_sent=ok"* ]] && grep -q "e2e-parent@example.com | Payment link for E2E-Test" $LOG'
PAY=$(q "echo SDSC_Pay::url($I1,$CID);")
L=$(curl -s -o /tmp/e2e-pay.html -w '%{http_code} %{redirect_url}' "$PAY")
chk "pay link opens Stripe checkout (test mode)" '[[ "$L" == *" https://checkout.stripe.com/"* ]]' || echo "      pay: $L $(sed 's/<[^>]*>/ /g' /tmp/e2e-pay.html | tr -s ' \n' ' ' | cut -c1-240)"
echo "== Clean up test data"
q "global \$wpdb; \$t=SDSC_Registration::table(); \$rows=\$wpdb->get_results(\"SELECT id, registrant_id FROM \$t WHERE email LIKE 'e2e-%@example.com'\"); foreach(\$rows as \$r){ if(\$r->registrant_id) wp_delete_post((int)\$r->registrant_id,true); \$wpdb->delete(\$t,array('id'=>\$r->id)); if(class_exists('SDSC_Pay')) \$wpdb->delete(SDSC_Pay::table(),array('reg_id'=>\$r->id)); delete_transient('sdsc_payparent_'.\$r->id); } echo 'removed '.count(\$rows).\" test registrations\n\";"
rm -f $MU/zz-e2e-mail.php
echo "e-mails captured (not sent):"; sed 's/^/   /' $LOG
echo "RESULT: $P passed, $F failed"
