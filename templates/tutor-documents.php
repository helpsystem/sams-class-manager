<?php
/** Tutor LMS dashboard → "Student documents" (office) / "My documents" (students). Drawn by assets/docs.js. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$office = class_exists( 'SDSC_Docs' ) && SDSC_Docs::is_office();
?>
<div class="tutor-dashboard-content-inner">
	<div id="sdd" class="sdd<?php echo $office ? ' sdd--office' : ''; ?>">
		<header class="sdd-head">
			<div>
				<h3><?php echo $office ? 'Documents — students and instructors' : 'My documents'; ?></h3>
				<p class="sdd-lead"><?php echo $office
					? 'ID cards, permits, practice logs and other papers, kept in each student’s file. Scan on the office scanner and drop the files here, or take photos with your phone.'
					: 'Add photos or scans of the documents the school asks for — for example your learner’s permit (front and back) or your practice log. Only the school office can see them.'; ?></p>
			</div>
		</header>
		<div class="sdd-app" aria-live="polite"><p class="sdd-loading">Loading…</p></div>
		<p class="sdd-privacy">🔒 Documents are stored encrypted and are never public. Every time a document is opened it is recorded.</p>
	</div>
</div>
