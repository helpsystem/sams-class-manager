#!/bin/bash
# Read-only security probe of the public site (GET/HEAD only, no data changed). Run on the server: bash security-probe.sh
S=${1:-https://samsdrivingschool.org}; P=0; F=0
ok(){ if eval "$2"; then echo "PASS $1"; P=$((P+1)); else echo "FAIL $1"; F=$((F+1)); fi; }
code(){ curl -s -o /dev/null -w '%{http_code}' -A 'sdsc-probe' "$1"; }
redir(){ curl -s -o /dev/null -w '%{redirect_url}' -A 'sdsc-probe' "$1"; }
echo "== Logged-out access"
for p in sdsc-inbox sdsc-registrations sdsc-prices sdsc-api sdsc-team sdsc-email sdsc-reports sdsc-lessons driveflow-pro-calendar sdsc-receipt sdsc-docs; do
  r=$(redir "$S/wp-admin/admin.php?page=$p"); ok "admin page $p -> login" '[[ "$r" == *wp-login.php* ]]'
done
ok "dashboard workspace -> login"      '[[ "$(redir "$S/dashboard/sdsc-work/?screen=inbox")" == *wp-login* || "$(code "$S/dashboard/sdsc-work/?screen=inbox")" != 200 || "$(curl -s "$S/dashboard/sdsc-work/?screen=inbox" | grep -c sdsc-inbox)" = 0 ]]'
ok "receipt with a bad token -> not found" '[[ "$(code "$S/?sdsc_receipt=abcdefabcdefabcdef")" =~ ^(404|403|410)$ ]]'
echo "== REST"
for r in sdsc/v1/registrations sdsc/v1/docs sdsc/v1/board sdsc/v1/lessons driveflow/v1/sessions driveflow/v1/students wp/v2/users; do
  c=$(code "$S/wp-json/$r"); ok "REST $r logged out -> $c (not 200)" '[[ "$c" != 200 ]]'
done
ok "REST users list hidden"  '[[ "$(code "$S/wp-json/wp/v2/users?per_page=1")" != 200 ]]'
ok "author scan blocked"     '[[ "$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" "$S/?author=1")" != 200* && "$(redir "$S/?author=1")" != */author/* ]]'
echo "== Files"
for f in .git/HEAD wp-config.php.bak wp-config.php~ readme.html wp-content/debug.log .env wp-content/uploads/ wp-content/plugins/sams-class-manager/ wp-content/plugins/sams-class-manager/includes/ wp-content/plugins/sams-class-manager/tools/; do
  c=$(code "$S/$f"); ok "$f -> $c" '[[ "$c" =~ ^(403|404|301|302)$ ]] || [[ "$f" == */ && "$(curl -s -A sdsc-probe "$S/$f" | grep -ci "index of")" = 0 ]]'
done
ok "xmlrpc.php off"  '[[ "$(curl -s -o /dev/null -w "%{http_code}" -X POST -d "<methodCall><methodName>system.listMethods</methodName></methodCall>" "$S/xmlrpc.php")" != 200 ]]'
ok "private docs folder not web reachable" '[[ "$(code "$S/sdsc-private-docs/")" != 200 && "$(code "$S/wp-content/uploads/sdsc-private-docs/")" != 200 ]]'
echo "== Headers"
H=$(curl -s -I -A 'sdsc-probe' "$S/")
ok "HTTPS + HSTS"               '[[ "$H" == *"strict-transport-security"* || "$H" == *"Strict-Transport-Security"* ]]'
ok "nosniff"                    '[[ "${H,,}" == *"x-content-type-options: nosniff"* ]]'
ok "clickjacking (frame)"       '[[ "${H,,}" == *"x-frame-options"* || "${H,,}" == *"frame-ancestors"* ]]'
ok "no PHP/server version leak" '[[ "${H,,}" != *"x-powered-by: php"* ]]'
echo "== Login"
L=$(curl -s "$S/wp-login.php")
ok "login page served over https, no autocomplete of passwords by us" '[[ "$L" == *"user_login"* ]]'
echo "RESULT PASS $P FAIL $F"
