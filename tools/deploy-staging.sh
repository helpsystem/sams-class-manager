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
W=$(mktemp -d); curl -fsSL https://codeload.github.com/helpsystem/sams-class-manager/tar.gz/${1:-refs/heads/main} | tar -xz -C $W
SRC=$(ls -d $W/sams-class-manager-*)
for d in includes assets templates; do cp -a $SRC/$d/. $PL/$d/; done
python3 $SRC/tools/english-ui.py $PL
python3 $SRC/tools/driveflow-security-fixes.py $DF
# In-car app: DriveFlow template hands the screen to SDSC_CarApp (classic form kept as fallback).
cp $SRC/driveflow/instructor-form-classic.php $DF/templates/instructor-form-classic.php
cp $SRC/driveflow/instructor-form.php $DF/templates/instructor-form.php
cp $SRC/driveflow/tv-board-classic.php $DF/templates/tv-board-classic.php
cp $SRC/driveflow/tv-board.php $DF/templates/tv-board.php
# main file: fleet -> DriveFlow bridge, add Zoom roll call, version 0.9.4
python3 - "$MAIN" "$(echo ${1:-$(date +%s)} | cut -c1-7)" <<'PY'
import re,sys
p=sys.argv[1]; s=open(p).read()
s=s.replace("includes/class-fleet.php","includes/class-dfbridge.php").replace("SDSC_Fleet::init();","SDSC_DriveFlow::init();")
if "class-zoomroll.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-schedule\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-schedule.php","class-zoomroll.php")+s[m.end():]
if "SDSC_ZoomRoll::init();" not in s:
    s=s.replace("SDSC_DriveFlow::init();","SDSC_DriveFlow::init(); SDSC_ZoomRoll::init();",1)
if "class-pay.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-registration\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-registration.php","class-pay.php")+s[m.end():]
if "class-preview.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-pay.php","class-preview.php")+s[m.end():]
if "SDSC_Preview::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Preview::init();",1)
if "class-overview.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-pay.php","class-overview.php")+s[m.end():]
if "SDSC_Overview::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Overview::init();",1)
if "class-short.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-pay.php","class-short.php")+s[m.end():]
if "SDSC_Short::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Short::init();",1)
if "class-reports.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-pay.php","class-reports.php")+s[m.end():]
if "SDSC_Reports::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Reports::init();",1)
if "class-policies.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-pay.php","class-policies.php")+s[m.end():]
if "SDSC_Policies::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Policies::init();",1)
if "class-look.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-pay.php","class-look.php")+s[m.end():]
if "SDSC_Look::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Look::init();",1)
if "class-workspace.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-pay.php","class-workspace.php")+s[m.end():]
if "SDSC_Workspace::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Workspace::init();",1)
if "class-onboard.php" not in s:
    m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
    s=s[:m.end()]+m.group(0).replace("class-pay.php","class-onboard.php")+s[m.end():]
if "SDSC_Onboard::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Onboard::init();",1)
for f,c in (("class-notify.php","SDSC_Notify"),("class-chat.php","SDSC_Chat"),("class-carapp.php","SDSC_CarApp"),("class-promo.php","SDSC_Promo"),("class-tv.php","SDSC_TV"),("class-private.php","SDSC_Private")):
    if f not in s:
        m=re.search(r"require_once[^\n]*includes/class-pay\.php[^\n]*\n",s)
        s=s[:m.end()]+m.group(0).replace("class-pay.php",f)+s[m.end():]
    if c+"::init();" not in s:
        s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); "+c+"::init();",1)
if "SDSC_Pay::init();" not in s:
    s=s.replace("SDSC_ZoomRoll::init();","SDSC_ZoomRoll::init(); SDSC_Pay::init();",1)
s=re.sub(r"(Version:\s*)0\.9\.\d+",r"\g<1>0.9.4",s); s=re.sub(r"('SDSC_VERSION',\s*')[0-9][0-9A-Za-z.\-]*",r"\g<1>0.9.4."+sys.argv[2],s)
open(p,'w').write(s)
PY
grep -n "class-pay\|Pay::init\|dfbridge\|zoomroll\|DriveFlow::init\|ZoomRoll::init\|Version:\|SDSC_VERSION'" $MAIN
for f in $MAIN $PL/includes/*.php $PL/templates/*.php $DF/driveflow-pro.php $DF/includes/class-credit-manager.php $DF/templates/instructor-form.php $DF/templates/tv-board.php; do php -l $f >/dev/null || { echo "LINT FAIL $f"; exit 1; }; done; echo "lint ok"
chown -R testsaman:testsaman $PL $DF
sudo -u testsaman wp --path=$ST rewrite flush 2>&1 | grep -v SERVER_NAME || true
curl -s -o /dev/null -w "purge %{http_code}\n" -X PURGE -H 'Host: testsaman.samsdrivingschool.org' http://127.0.0.1:6081/ || true
sudo -u testsaman wp --path=$ST eval 'echo "classes: ", class_exists("SDSC_ZoomRoll")?"zoomroll ":"-", class_exists("SDSC_DriveFlow")?"dfbridge ":"-", class_exists("SDSC_Pay")?"pay ":"-", class_exists("SDSC_Preview")?"preview ":"-", class_exists("SDSC_Overview")?"overview ":"-", class_exists("SDSC_Short")?"short ":"-", class_exists("SDSC_Reports")?"reports ":"-", class_exists("SDSC_Policies")?"policies ":"-", class_exists("SDSC_Look")?"look ":"-", class_exists("SDSC_Workspace")?"workspace ":"-", class_exists("SDSC_Onboard")?"onboard ":"-", class_exists("SDSC_Notify")?"notify ":"-", class_exists("SDSC_Chat")?"chat ":"-", class_exists("SDSC_CarApp")?"carapp ":"-", class_exists("SDSC_Promo")?"promo ":"-", class_exists("SDSC_TV")?"tv ":"-", class_exists("SDSC_Private")?"private ":"-", "on=", (int)SDSC_DriveFlow::on(), " ver=", SDSC_VERSION, "\n"; global $wpdb; echo "staff_note col: ", $wpdb->get_var("SHOW COLUMNS FROM ".SDSC_Schedule::table()." LIKE \"staff_note\"") ?: "missing", "\n";' 2>&1 | grep -v SERVER_NAME
rm -rf $W; echo DONE
