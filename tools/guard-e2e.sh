#!/bin/bash
# Live sign-up protection test on staging. Creates only e2e-gN-<ts>@example.com accounts and removes them.
S=/home/testsaman/htdocs/testsaman.samsdrivingschool.org; W="sudo -u testsaman wp --path=$S"; U=https://testsaman.samsdrivingschool.org/wp-login.php?action=register
TS=$(date +%s); P=0; F=0
ok(){ if [ "$2" = "$3" ]; then echo "PASS $1"; P=$((P+1)); else echo "FAIL $1 (got $2, want $3)"; F=$((F+1)); fi; }
reset(){ $W eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"_transient%sdsc_su_%\"");' >/dev/null 2>&1; }
reg(){ curl -s -o /dev/null -A "Mozilla/5.0 test" -e "$U" --data-urlencode "user_login=$1" --data-urlencode "user_email=$2" --data "sdsc_hp_t=$3" --data-urlencode "sdsc_hp_url=$4" --data "wp-submit=Register" "$U"; $W user get "$2" --field=ID >/dev/null 2>&1 && echo yes || echo no; }
reset
ok "real person (Gmail-like, normal speed) can sign up"   "$(reg e2eg1$TS e2e-g1-$TS@example.com 12 '')" yes
ok "form sent in 1 second is refused (bot speed)"          "$(reg e2eg2$TS e2e-g2-$TS@example.com 1 '')"  no
ok "throwaway inbox (mailinator) is refused"               "$(reg e2eg3$TS e2e-g3-$TS@mailinator.com 12 '')" no
ok "made-up domain is refused"                             "$(reg e2eg4$TS e2e-g4-$TS@qzxv-not-real-$TS.com 12 '')" no
ok "hidden bot-trap field filled is refused"               "$(reg e2eg5$TS e2e-g5-$TS@example.com 12 'http://spam')" no
ok "2nd account from same network allowed"                 "$(reg e2eg6$TS e2e-g6-$TS@example.com 12 '')" yes
ok "3rd account from same network allowed"                 "$(reg e2eg7$TS e2e-g7-$TS@example.com 12 '')" yes
ok "4th account in the same hour is refused"               "$(reg e2eg8$TS e2e-g8-$TS@example.com 12 '')" no
ok "user list hidden (?author=1)"                          "$(curl -s -o /dev/null -w '%{http_code}' https://testsaman.samsdrivingschool.org/?author=1 | grep -qv 200 && echo hidden || echo open)" hidden
ok "user list hidden (REST API)"                           "$(curl -s -o /dev/null -w '%{http_code}' https://testsaman.samsdrivingschool.org/wp-json/wp/v2/users | grep -qv 200 && echo hidden || echo open)" hidden
# clean up: test accounts, network counters, the trap's blocklist entry
for i in 1 2 3 4 5 6 7 8; do for d in example.com mailinator.com qzxv-not-real-$TS.com; do id=$($W user get e2e-g$i-$TS@$d --field=ID 2>/dev/null); [ -n "$id" ] && $W user delete $id --yes >/dev/null 2>&1; done; done
reset
$W eval '$o=get_option("sdsc_blocklist",array()); if(!empty($o["emails"])){$o["emails"]=array_values(array_filter($o["emails"],function($e){return strpos($e,"e2e-g")!==0;})); update_option("sdsc_blocklist",$o,false);}' >/dev/null 2>&1
echo "left over test accounts: $($W user list --search='e2e-g*-'$TS'*' --format=count 2>/dev/null)"
echo "RESULT: $P passed, $F failed"
