#!/bin/bash
# Deploy sams-class-manager (GitHub main) to STAGING only + DriveFlow security fixes.
# Usage on the server (root): bash deploy-staging.sh
set -euo pipefail
ST=/home/testsaman/htdocs/testsaman.samsdrivingschool.org
PL=$ST/wp-content/plugins/sams-class-manager
DF=$ST/wp-content/plugins/driveflow-pro
MAIN=$PL/sams-class-manager.php
[ -f "$MAIN" ] || { echo "main file missing: $MAIN"; exit 1; }
TS=$(date +%Y%m%d-%H%M%S); mkdir -p /root/site-backups
tar -C $ST/wp-content/plugins -czf /root/site-backups/staging-plugins-$TS.tgz sams-class-manager driveflow-pro
echo "backup: /root/site-backups/staging-plugins-$TS.tgz"
W=$(mktemp -d); curl -fsSL https://codeload.github.com/helpsystem/sams-class-manager/tar.gz/refs/heads/main | tar -xz -C $W
SRC=$(ls -d $W/sams-class-manager-*)
for d in includes assets templates; do cp -a $SRC/$d/. $PL/$d/; done
python3 $SRC/tools/driveflow-security-fixes.py $DF
# main file: fleet -> DriveFlow bridge, add Zoom roll call, version 0.9.4
python3 - "$MAIN" <<'PY'
import re,sys
p=sys.argv[1]; s=open(p).read()
s=s.replace("includes/class-fleet.php","includes/class-dfbridge.php").replace("SDSC_Fleet::init();","SDSC_DriveFlow::init();")
if "class-zoomroll.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-schedule\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-schedule.php","class-zoomroll.php")+s[m.end():]
if "SDSC_ZoomRoll::init();" not in s:
    s=s.replace("SDSC_DriveFlow::init();","SDSC_DriveFlow::init(); SDSC_ZoomRoll::init();",1)
s=re.sub(r"(Version:\s*)0\.9\.\d+",r"\g<1>0.9.4",s); s=re.sub(r"('SDSC_VERSION',\s*')0\.9\.\d+",r"\g<1>0.9.4",s)
open(p,'w').write(s)
PY
grep -n "dfbridge\|zoomroll\|DriveFlow::init\|ZoomRoll::init\|Version:\|SDSC_VERSION'" $MAIN
for f in $MAIN $PL/includes/*.php $PL/templates/*.php $DF/driveflow-pro.php $DF/includes/class-credit-manager.php; do php -l $f >/dev/null || { echo "LINT FAIL $f"; exit 1; }; done; echo "lint ok"
chown -R testsaman:testsaman $PL $DF
curl -s -o /dev/null -w "purge %{http_code}\n" -X PURGE -H 'Host: testsaman.samsdrivingschool.org' http://127.0.0.1:6081/ || true
sudo -u testsaman wp --path=$ST eval 'echo "classes: ", class_exists("SDSC_ZoomRoll")?"zoomroll ":"-", class_exists("SDSC_DriveFlow")?"dfbridge ":"-", "on=", (int)SDSC_DriveFlow::on(), " ver=", SDSC_VERSION, "\n"; global $wpdb; echo "staff_note col: ", $wpdb->get_var("SHOW COLUMNS FROM ".SDSC_Schedule::table()." LIKE \"staff_note\"") ?: "missing", "\n";' 2>&1 | grep -v SERVER_NAME
rm -rf $W; echo DONE
