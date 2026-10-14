#!/bin/bash
# Move the new site (staging) onto samsdrivingschool.org. Run as root, one phase at a time:
#   bash switch-live.sh banner|sync|backup|files|db|config|final|open|check
# T = folder of this script (sync tools live next to it).
set -uo pipefail
T=$(cd "$(dirname "$0")" && pwd)
ST=/home/testsaman/htdocs/testsaman.samsdrivingschool.org
PR=/home/user/htdocs/srv2042550.hstgr.cloud
NW="sudo -u testsaman wp --path=$ST"
PW="sudo -u user wp --path=$PR"
B=/root/backups/switch-20261014
mkdir -p $B; chmod 700 /root/backups $B
ban() { varnishadm 'ban req.url ~ .' >/dev/null 2>&1 && echo "varnish purged" || echo "varnish: not purged"; }
code() { curl -s -o /dev/null -w '%{http_code}' -m 30 "$1"; }

case "${1:-}" in
banner)
	echo '<?php $upgrading = time(); ?>' > $PR/.maintenance; chown user:user $PR/.maintenance
	ban; sleep 1
	echo "home: $(code https://samsdrivingschool.org/)  (503 = banner on)"
	echo "wp-cli on live still works: $($PW option get siteurl 2>&1 | tail -1)"
	;;
sync)
	sudo -u user env SINCE='2026-10-05 00:00:00' FRM_AFTER=761 wp --path=$PR eval-file $T/sync-export.php > $B/sync-final.json 2>$B/sync-export.err
	echo "export: $(wc -c < $B/sync-final.json) bytes"; tail -2 $B/sync-export.err
	install -m 644 $B/sync-final.json /tmp/sdsc-sync-final.json
	echo "--- dry run"; sudo -u testsaman env SYNC=/tmp/sdsc-sync-final.json wp --path=$ST eval-file $T/sync-import.php 2>&1 | tail -12
	echo "--- apply";  sudo -u testsaman env SYNC=/tmp/sdsc-sync-final.json APPLY=1 wp --path=$ST eval-file $T/sync-import.php 2>&1 | tail -12
	rm -f /tmp/sdsc-sync-final.json
	;;
backup)
	$PW db export - 2>/dev/null | gzip > $B/live-db-before.sql.gz
	tar -C $PR -czf $B/live-code-before.tgz wp-config.php wp-content/plugins wp-content/themes wp-content/mu-plugins 2>/dev/null
	cp -p $PR/wp-config.php $B/live-wp-config.php
	ls -la $B; df -h / | tail -1
	;;
files)
	rsync -a --delete --exclude 'sdsc-staging-guard.php' $ST/wp-content/mu-plugins/ $PR/wp-content/mu-plugins/
	rsync -a --delete $ST/wp-content/plugins/ $PR/wp-content/plugins/
	rsync -a --delete $ST/wp-content/themes/ $PR/wp-content/themes/
	rsync -a $ST/wp-content/uploads/ $PR/wp-content/uploads/
	[ -d $ST/wp-content/languages ] && rsync -a $ST/wp-content/languages/ $PR/wp-content/languages/
	chown -R user:user $PR/wp-content/mu-plugins $PR/wp-content/plugins $PR/wp-content/themes $PR/wp-content/uploads $PR/wp-content/languages 2>/dev/null
	mkdir -p /home/user/htdocs/sdsc-private-docs
	rsync -a /home/testsaman/htdocs/sdsc-private-docs/ /home/user/htdocs/sdsc-private-docs/
	chown -R user:user /home/user/htdocs/sdsc-private-docs; chmod 700 /home/user/htdocs/sdsc-private-docs
	echo "mu-plugins: $(ls $PR/wp-content/mu-plugins | tr '\n' ' ')"
	echo "plugins: $(ls $PR/wp-content/plugins | wc -l)  private docs: $(find /home/user/htdocs/sdsc-private-docs -type f | wc -l) files"
	;;
db)
	$NW search-replace 'testsaman.samsdrivingschool.org' 'samsdrivingschool.org' --all-tables --skip-plugins --skip-themes --export=/tmp/sdsc-new.sql 2>&1 | tail -2
	chmod 644 /tmp/sdsc-new.sql
	$PW db import /tmp/sdsc-new.sql --skip-plugins --skip-themes 2>&1 | tail -1
	rm -f /tmp/sdsc-new.sql
	$PW search-replace "$ST" "$PR" --all-tables --skip-plugins --skip-themes 2>&1 | tail -1
	$PW search-replace '/home/testsaman/htdocs' '/home/user/htdocs' --all-tables --skip-plugins --skip-themes 2>&1 | tail -1
	echo "siteurl: $($PW option get siteurl --skip-plugins --skip-themes)  home: $($PW option get home --skip-plugins --skip-themes)"
	echo "left 'testsaman' mentions:"; $PW search-replace testsaman testsaman --all-tables --dry-run --report-changed-only --skip-plugins --skip-themes 2>&1 | tail -8
	;;
config)
	cp -p $PR/wp-config.php $B/live-wp-config-before-salts.php
	python3 - "$ST/wp-config.php" "$PR/wp-config.php" <<'PY'
import re,sys
src=open(sys.argv[1]).read(); dst=open(sys.argv[2]).read()
keys=['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']
n=0
for k in keys:
    pat=re.compile(r"^\s*define\(\s*'"+k+r"'.*$",re.M)
    s=pat.search(src); d=pat.search(dst)
    if s and d: dst=dst[:d.start()]+s.group(0)+dst[d.end():]; n+=1
open(sys.argv[2],'w').write(dst); print('salts copied:',n)
PY
	chown user:user $PR/wp-config.php; chmod 640 $PR/wp-config.php 2>/dev/null
	a=$($NW eval 'echo md5(wp_salt("auth").wp_salt("secure_auth"));' --skip-plugins --skip-themes); b=$($PW eval 'echo md5(wp_salt("auth").wp_salt("secure_auth"));' --skip-plugins --skip-themes)
	[ "$a" = "$b" ] && echo "keys match: saved passwords and API keys can be read on live" || echo "KEYS DIFFER"
	;;
final)
	$PW option update blog_public 1
	$PW eval 'update_option("sdsc_course_gate_since", current_time("mysql"), false); echo "gate since ".get_option("sdsc_course_gate_since")."\n";'
	$PW plugin is-active tutor-pro 2>/dev/null && $PW plugin deactivate tutor-pro
	$PW plugin auto-updates enable tutor 2>&1 | tail -1
	[ "$($PW post get 1379 --field=post_type 2>/dev/null)" = "wpcode" ] && $PW post update 1379 --post_status=draft 2>&1 | tail -1
	echo "stripe mode: $($PW eval 'echo SDSC_Api::mode();')"
	$PW rewrite flush 2>&1 | tail -1; $PW cache flush 2>&1 | tail -1; $PW elementor flush-css 2>&1 | tail -1
	ban
	;;
open)
	rm -f $PR/.maintenance $ST/sdsc-maintenance-preview.html; ban; sleep 1
	echo "home: $(code https://samsdrivingschool.org/)"
	;;
check)
	n=0; bad=0
	for u in $($PW post list --post_type=page,post,courses --post_status=publish --field=url 2>/dev/null) https://samsdrivingschool.org/wp-login.php; do
		n=$((n+1)); c=$(code "$u"); [ "$c" = 200 ] || { bad=$((bad+1)); echo "$c $u"; }
	done
	echo "pages checked=$n not-200=$bad"
	curl -s -m 30 https://samsdrivingschool.org/ | grep -o 'testsaman[^"< ]*' | sort -u | head -5
	echo "robots: $(curl -s -m 20 https://samsdrivingschool.org/robots.txt | head -3 | tr '\n' ' ')"
	;;
*) echo "phase?"; exit 1;;
esac
