# SDS Scan — office scanner bridge

Source of `assets/scan/SDS-Scan-Windows.zip` and `assets/scan/SDS-Scan-Mac.zip`.

Runs on the office computer, listens on http://127.0.0.1:52700 (only for samsdrivingschool.org pages) and scans
from the Brother DCP-L2550DW (or any AirPrint/eSCL scanner) over the network. Finds the scanner with Bonjour
(_uscan._tcp), then by trying the local /24 network; the address is remembered.

Build: `npm i multicast-dns@7.2.5 @yao-pkg/pkg@6.5.1 && npx pkg bridge.js -t node20-win-x64,node20-macos-x64,node20-macos-arm64 --out-path dist`
then zip with the install scripts (see the session notes).
