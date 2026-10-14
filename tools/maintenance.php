<?php
/**
 * Shown by WordPress while wp-content/../.maintenance exists (site switch / big updates).
 * Copy to wp-content/maintenance.php. 503 + Retry-After tells search engines it is temporary.
 */
http_response_code( 503 );
header( 'Retry-After: 1800' );
header( 'Content-Type: text/html; charset=utf-8' );
header( 'Cache-Control: no-store' );
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>We're upgrading — Sam's Driving School</title>
<style>
:root{--g:#00703c;--gd:#003d20;--y:#ffc20e;--ink:#15191d}
*{box-sizing:border-box}
html,body{margin:0;height:100%}
body{min-height:100vh;display:grid;place-items:center;padding:24px 16px;font-family:Overpass,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#fff;background:radial-gradient(120% 120% at 100% 0%,rgba(255,194,14,.22),transparent 45%),linear-gradient(140deg,var(--g),var(--gd) 70%);overflow-x:hidden}
.road{position:fixed;left:-10%;right:-10%;bottom:44px;height:8px;background:repeating-linear-gradient(90deg,var(--y) 0 60px,transparent 60px 110px);opacity:.55;transform:skewX(-20deg);animation:r 2.2s linear infinite}
@keyframes r{to{background-position:110px 0}}
.card{position:relative;z-index:1;width:min(640px,100%);padding:clamp(26px,5vw,44px);border-radius:26px;background:rgba(255,255,255,.08);box-shadow:inset 0 0 0 1px rgba(255,255,255,.22),0 40px 80px -40px rgba(0,0,0,.6);text-align:center;backdrop-filter:blur(6px)}
.sign{width:96px;height:96px;margin:0 auto 18px;display:grid;place-items:center;background:var(--y);color:var(--ink);transform:rotate(45deg);border-radius:14px;box-shadow:0 0 0 5px var(--ink),0 0 0 8px var(--y)}
.sign svg{transform:rotate(-45deg);width:46px;height:46px}
.kick{display:inline-block;padding:5px 14px;border-radius:999px;background:var(--y);color:var(--ink);font-weight:900;font-size:.76rem;letter-spacing:.12em;text-transform:uppercase}
h1{margin:16px 0 10px;font-size:clamp(1.9rem,5vw,2.8rem);line-height:1.05;font-weight:900;letter-spacing:-.02em}
h1 span{color:var(--y)}
p{margin:0 auto 22px;max-width:46ch;font-size:1.06rem;line-height:1.6;color:rgba(255,255,255,.9)}
.bar{height:8px;margin:0 auto 24px;max-width:360px;border-radius:99px;background:rgba(255,255,255,.18);overflow:hidden}
.bar i{display:block;height:100%;width:40%;border-radius:99px;background:var(--y);animation:b 1.8s ease-in-out infinite}
@keyframes b{0%{transform:translateX(-100%)}100%{transform:translateX(260%)}}
.btns{display:flex;flex-wrap:wrap;justify-content:center;gap:10px}
.btns a{display:inline-flex;align-items:center;gap:8px;min-height:48px;padding:0 22px;border-radius:999px;font-weight:800;text-decoration:none}
.call{background:var(--y);color:var(--ink)}
.mail{color:#fff;box-shadow:inset 0 0 0 2px rgba(255,255,255,.6)}
.foot{margin-top:22px;font-size:.88rem;color:rgba(255,255,255,.75)}
@media (prefers-reduced-motion:reduce){.road,.bar i{animation:none}}
</style>
</head>
<body>
<div class="road" aria-hidden="true"></div>
<main class="card">
	<div class="sign" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/></svg></div>
	<span class="kick">Road work ahead</span>
	<h1>We're upgrading <span>our website</span></h1>
	<p>Sam's Driving School is moving to a faster, easier website. We'll be back in about 30 minutes. Classes, lessons and road tests go on as planned.</p>
	<div class="bar" aria-hidden="true"><i></i></div>
	<div class="btns">
		<a class="call" href="tel:+13017263030">Call (301) 726-3030</a>
		<a class="mail" href="mailto:info@samsdrivingschool.org">info@samsdrivingschool.org</a>
	</div>
	<p class="foot">751 Rockville Pike, Unit 9B, Rockville, MD 20852 · MVA-approved driving school</p>
</main>
</body>
</html>
