<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Guided tours for every Sam's School screen.
 *
 *  - Sam's School → Guided tours lists the tours of the screens the person can open (role-aware).
 *  - "Help & tours" in the top bar replays the tour of the current screen at any time.
 *  - A small "Tour" button sits on every Sam's School screen; it pulses until the person has seen that tour.
 *  - Steps point at parts of the screen; if a part is missing the step simply shows in the middle.
 *  Seen tours are remembered per person (user meta sdsc_tours_seen), not per browser.
 */
class SDSC_Tours {

	const META = 'sdsc_tours_seen';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 41 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'wp_ajax_sdsc_tour_seen', array( __CLASS__, 'ajax_seen' ) );
		add_action( 'wp_ajax_nopriv_sdsc_tour_seen', '__return_null' );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ), 30 );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Guided tours', 'Guided tours', 'read', 'sdsc-tours', array( __CLASS__, 'page' ) );
	}

	/* ------------------------------------------------------------ tour content */

	/** key => [feature key or capability, title, one-line summary, admin URL, screen match, steps] */
	public static function tours() {
		$s = function ( $target, $title, $content, $place = 'auto' ) {
			return array( 'target' => $target, 'title' => $title, 'content' => $content, 'placement' => $place );
		};
		return array(
			'start' => array( 'tours', 'Welcome to Sam’s School', 'A two-minute look at the menu and where everything lives.', 'admin.php?page=sdsc-tours', 'sdsc-tours', array(
				$s( '', 'Welcome to Sam’s School', 'This short tour shows where the school’s tools live. You only see the screens your role can open. Use the arrow keys to move and Esc to leave at any time.', 'center' ),
				$s( '#toplevel_page_sdsc-dashboard', 'Your menu', 'Everything built for the school is under <b>Sam’s School</b>, sorted into groups.', 'right' ),
				'__groups__',
				$s( '#sdsc-tour-list', 'A tour for every screen', 'Each card opens that screen and walks you through it step by step.', 'top' ),
				$s( '#wp-admin-bar-sdsc-help', 'Help is always one click away', 'Open <b>Help &amp; tours</b> in the top bar to replay the tour of the screen you are on.', 'bottom' ),
			) ),
			'path_manager' => array( 'refunds', 'Manager: your day, step by step', 'A walk through the school day — which screen to open, in what order, and what to check.', 'admin.php?page=sdsc-tours', 'sdsc-tours', array(
				$s( '', 'The school day in 10 steps', 'This path shows the order that keeps everything on time. Each step names a screen — every screen also has its own tour (the green <b>Take the tour</b> button, bottom right).', 'center' ),
				$s( '', '1 · What’s new', 'Start in <b>Help → What’s new</b>. Every change to the website or your tools is explained there; the red counter shows what you haven’t read.', 'center' ),
				$s( '', '2 · Lesson calendar (DriveFlow)', 'Open <b>Lesson calendar</b>. Check today and tomorrow: every lesson has a student, an instructor and a car, and there are no red collision warnings. Block time off and car maintenance here.', 'center' ),
				$s( '', '3 · BTW lessons & fees', 'The badge on <b>BTW lessons &amp; fees</b> counts lessons whose outcome is missing. Mark no-shows and lessons without a permit — the $60 fee is added. Waive a fee only with a reason; the student and you both see the waiver.', 'center' ),
				$s( '', '4 · Texts to send', 'On the school iPhone, <b>Texts to send</b> lists every text that is due: confirmations, reminders, today’s lessons, Zoom reminders, birthdays. Tap <b>Text the next one</b> until the list is empty.', 'center' ),
				$s( '', '5 · Class attendance', 'For each Zoom class today, mark Present / Absent after class. Session 1 is mandatory; 4 absences are allowed after it — the counter warns you before a student goes over.', 'center' ),
				$s( '', '6 · Registrations', 'New online sign-ups land in <b>Registrations</b> with the signed contract. Add new behind-the-wheel students in DriveFlow <b>Students &amp; Credits</b> and send them their booking link.', 'center' ),
				$s( '', '7 · Refunds', 'When a student asks to leave, open <b>Refunds</b>. The calculator applies policy 15 (classes and drives already used are kept) and sends the refund to the card or records a manual one, with a receipt e-mail.', 'center' ),
				$s( '', '8 · Instructors', 'In DriveFlow <b>Instructors</b>: send new instructors their onboarding link, check signed agreements, and record payouts at the end of the pay period.', 'center' ),
				$s( '', '9 · Website', '<b>Banners &amp; alerts</b> for closures and holidays, <b>Prices</b> and <b>Discounts</b> for offers. The website never shows a price checkout doesn’t charge.', 'center' ),
				$s( '', '10 · Team', 'Administrators decide in <b>Team access</b> which screens instructors and front-desk staff can open. Never share your own login.', 'center' ),
			) ),
			'path_frontdesk' => array( 'lessons', 'Front desk: booking a lesson from a text', 'From the first text of a student to the confirmation — the exact order to follow.', 'admin.php?page=sdsc-tours', 'sdsc-tours', array(
				$s( '', 'A student texts to schedule', 'Ask the three questions every time: <b>full name</b>, <b>do you have your learner’s permit?</b> and <b>how many practice hours are logged?</b>', 'center' ),
				$s( '', '1 · Is the student in DriveFlow?', 'Open DriveFlow <b>Students &amp; Credits</b> and search the name. New student? <b>Register</b> them with phone and e-mail, the package and the number of lessons.', 'center' ),
				$s( '', '2 · Find free times', 'Open the <b>Lesson calendar</b>. Filter by instructor if the student needs a language (English, Spanish, Farsi). Free 2-hour slots are the empty cells.', 'center' ),
				$s( '', '3 · Offer two times', 'Text two options, for example “October 27, 1:00 or October 29, 1:00?”. Or send the student their <b>booking link</b> from Students &amp; Credits and let them pick.', 'center' ),
				$s( '', '4 · Book it', 'When they answer, <b>double-click</b> that slot, choose the student, instructor and car, and save. Red warnings mean the instructor or car is already busy or off.', 'center' ),
				$s( '', '5 · The confirmation goes out by itself', 'Within seconds the student gets the confirmation e-mail (date, place with directions, “bring your permit”, the free-cancel deadline and the $60 fee). The same text waits in <b>Texts to send</b> — send it from the school phone.', 'center' ),
				$s( '', '6 · Changes', 'To move a lesson, <b>drag</b> it on the calendar. The student is told automatically and the reminders start again for the new time.', 'center' ),
				$s( '', '7 · Cancellations', 'Open the lesson in <b>BTW lessons &amp; fees</b> → <b>Cancel</b>, and enter when the student told you. In time: no fee and a cancellation notice. Late: the $60 fee is added — you can waive it with a reason. It is cancelled in DriveFlow as well.', 'center' ),
				$s( '', '8 · Every morning', 'Work through <b>Texts to send</b>: today’s lessons, reminders for students who have not confirmed before the free-cancel deadline, and Zoom class reminders.', 'center' ),
			) ),
			'lessons' => array( 'lessons', 'BTW lessons & fees', 'Confirmations, the 48-hour rule, the $60 fee, waivers and payments.', 'admin.php?page=sdsc-lessons', 'sdsc-lessons', array(
				$s( '', 'Behind-the-wheel lessons & fees', 'Every lesson booked on the DriveFlow calendar appears here within seconds. This screen applies the school policy: confirmation, reminders, the 48-hour rule, the $60 fee and waivers.', 'center' ),
				$s( '.sdo-tabs', 'Sections', '<b>Schedule</b> — lessons by day. <b>Fees</b> — what is due, paid or waived. <b>Student history</b> — one student’s lessons and fees. <b>Settings</b> — fee, hours, meeting place and directions. The last tab opens the DriveFlow calendar to book or move a lesson.', 'bottom' ),
				$s( '.sdl-row', 'One lesson', 'Time, student, lesson number and instructor. The chips show whether the confirmation went out, whether the student pressed <b>I’ll be there</b> or <b>I can’t make it</b>, and until when they can cancel for free.', 'bottom' ),
				$s( '.sdl-act', 'After the lesson', 'When the instructor submits the evaluation in DriveFlow the lesson closes as <b>Completed</b> by itself. Use <b>No-show</b> or <b>No permit</b> when that happened — the $60 fee is added.', 'left' ),
				$s( '', 'Cancelling', 'Open the lesson and press <b>Cancel</b>. Enter <b>when the student told you</b>: before the deadline means no fee and a cancellation notice; later means the fee. Cancelling here also cancels the lesson in DriveFlow. A lesson cancelled directly in DriveFlow less than 48 hours before also gets the fee — waive it if the school cancelled.', 'center' ),
				$s( '', 'Fees', 'On <b>Fees</b>: <b>Waive</b> needs a short reason (the student is told there is nothing to pay, and the record stays on both sides). <b>Mark paid</b> for cash or card at the office. Online payments close the fee by themselves.', 'center' ),
				$s( '', 'What the student receives', 'Confirmation e-mail in English or Spanish with directions and the policy, one-tap <b>I’ll be there / I can’t make it</b> buttons, a link to add all classes to Apple, Google or Outlook calendar, and reminders before the free-cancel deadline and one hour before.', 'center' ),
			) ),
			'texts' => array( 'texts', 'Texts to send', 'The school iPhone’s to-do list: every text that is due, one tap each.', 'admin.php?page=sdsc-texts', 'sdsc-texts', array(
				$s( '', 'Texts to send', 'Open this page on the school iPhone in Safari and choose <b>Share → Add to Home Screen</b>. It works like an app. Texts are sent from the phone itself, so they cost nothing.', 'center' ),
				$s( '.sdq-next', 'Text the next one', 'Opens Messages with the number and the message already written. You only press Send, come back, and tap again for the next one.', 'bottom' ),
				$s( '.sdq-card', 'One text', 'Who, why it is due, and the message. <b>Text</b> marks it done after you open Messages, <b>Skip</b> hides it, <b>Undo</b> brings it back.', 'bottom' ),
				$s( '.sdo-lang', 'English or Spanish', 'Switch the language of this text before sending.', 'left' ),
				$s( '', 'What shows up here', 'Lesson confirmations · reminders when a student hasn’t confirmed before the free-cancel deadline · today’s lessons · cancellations · fees and waivers · Zoom class tomorrow / today · birthdays and license anniversaries. No advertising.', 'center' ),
			) ),
			'attendance' => array( 'attendance', 'Class attendance', 'Zoom class attendance, confirmations and the absence rule.', 'admin.php?page=sdsc-attendance', 'sdsc-attendance', array(
				$s( '', 'Class attendance', 'One sheet per Zoom class: who confirmed, who said they can’t come, and who was really there.', 'center' ),
				$s( '.sdo-filter', 'Pick the class', 'Choose the class (course code and dates).', 'bottom' ),
				$s( '.sdl-att', 'The sheet', 'One row per student, one column per session. ✓ or ✗ shows the student’s own answer; set <b>P</b> (present) or <b>A</b> (absent) after the class, then Save.', 'top' ),
				$s( '', 'The rules', 'Session 1 is mandatory (MVA rule). After it, 4 absences are allowed and the student must tell the school. The counter turns red before a student goes over.', 'center' ),
				$s( '', 'Timetable', 'If a class shows “no timetable”, open the class and fill <b>Timetable &amp; calendar</b>: days, start and end time, number of sessions. Holidays on the closed list are skipped by themselves. Reminders then go out the day before and one hour before.', 'center' ),
			) ),
			'sms' => array( 'sms', 'Text messages', 'Automatic texts through Twilio, once it is connected.', 'admin.php?page=sdsc-sms', 'sdsc-sms', array(
				$s( '', 'Text messages', 'When Twilio is connected (and the business number is registered for A2P 10DLC), confirmations and reminders can go out as texts by themselves. Until then, use <b>Texts to send</b> on the school phone.', 'center' ),
				$s( '.sdo-settings', 'Automatic texts', 'Switch automatic texting on or off, and choose whether lesson messages are texted too. Students can reply STOP at any time.', 'right' ),
				$s( '.sdo-two', 'Test and log', 'Send a test to your own phone, and see the last 100 texts with their delivery status.', 'top' ),
			) ),
			'refunds' => array( 'refunds', 'Refunds', 'Full or prorated refunds by the refund policy.', 'admin.php?page=sdsc-refunds', 'sdsc-refunds', array(
				$s( '', 'Refunds', 'Refunds follow policy 15: from the $378 course, $11.50 is kept for each Zoom class attended and $88 for each behind-the-wheel session done; the rest goes back.', 'center' ),
				$s( '.sdo-tabs', 'Sections', '<b>Refunds made</b>, <b>New refund</b> and <b>Policy amounts</b> (change these only together with the written policy).', 'bottom' ),
				$s( '', 'Making a refund', 'Find the student → the payments are found in Stripe and WooCommerce → the calculator shows what is kept and what goes back → <b>Review</b> → <b>Confirm</b>. Card payments are refunded to the card; cash or Zelle is recorded as manual. The student gets a receipt e-mail and sees it in their dashboard.', 'center' ),
				$s( '', 'Only once', 'A refund cannot be sent twice by mistake, and only managers can open this screen.', 'center' ),
			) ),
			'df_calendar' => array( 'driveflow', 'Lesson calendar', 'Book, move and assign behind-the-wheel lessons.', 'admin.php?page=driveflow-pro-calendar', 'driveflow-pro-calendar', array(
				$s( '', 'Lesson calendar (DriveFlow)', 'The one place where behind-the-wheel lessons are booked and moved. Everything else — confirmations, reminders, fees, texts — follows from here automatically.', 'center' ),
				$s( '#df-cal-view-toggle', 'Two views', '<b>2-Hour Slots (Week)</b> — the whole week in lesson blocks. <b>Fleet Vehicles (Day)</b> — one column per car for the day.', 'bottom' ),
				$s( '#df-cal-instructor-filter', 'Filter', 'Show one instructor or one car only. Use it to find a free time for a student who needs a Spanish- or Farsi-speaking instructor.', 'bottom' ),
				$s( '#df-calendar-board', 'Book and move', '<b>Double-click</b> an empty slot to book a 2-hour lesson. <b>Drag</b> a lesson to move it — the student gets “your lesson has changed” by itself.', 'top' ),
				$s( '#df-assign-tray', 'Assign by dragging', 'Drag a student, instructor or car chip onto a lesson card to assign it.', 'top' ),
				$s( '#df-open-block-modal-btn', 'Block time', 'Instructor time off, a car in maintenance, or a school-wide closure. Blocked time cannot be booked.', 'bottom' ),
				$s( '', 'Collisions', 'If an instructor or car is already busy, a red warning appears and the booking is stopped.', 'center' ),
				$s( '', 'After you save', 'The student gets the policy confirmation by e-mail, the text waits in <b>Texts to send</b>, the instructor gets the lesson notice, and the lobby TV updates.', 'center' ),
			) ),
			'df_students' => array( 'driveflow', 'Students & Credits', 'Behind-the-wheel students, their lessons left and booking links.', 'admin.php?page=driveflow-pro-students', 'driveflow-pro-students', array(
				$s( '', 'Students & Credits', 'Every behind-the-wheel student with their package, lessons used and lessons left.', 'center' ),
				$s( '.wrap h1', 'Register a student', 'Add name, mobile and e-mail (the e-mail is where confirmations go), the package and the number of lessons.', 'bottom' ),
				$s( '', 'Extra lessons', '<b>Add extra sessions</b> when a student buys more lessons. Every change is kept in the student’s ledger.', 'center' ),
				$s( '', 'Booking link', '<b>Magic link</b> creates a private link the student uses to book their own lessons from open slots. Choose how long it stays valid; send it by e-mail or text.', 'center' ),
				$s( '', 'Profile', 'Open a student to see every lesson, the instructor’s scorecards and the ledger.', 'center' ),
			) ),
			'df_records' => array( 'driveflow', 'Sessions & Records', 'Every lesson with its evaluation, selfie and signature.', 'admin.php?page=driveflow-pro-records', 'driveflow-pro-records', array(
				$s( '', 'Sessions & Records', 'The lesson register: upcoming, active, completed and cancelled lessons with the instructor’s evaluation.', 'center' ),
				$s( '.wrap h1', 'Find a lesson', 'Filter by status. Open a lesson to see the scorecard, the instructor’s selfie and the student’s signature.', 'bottom' ),
				$s( '', 'Print for the file', '<b>Print</b> the lesson log or a scorecard for the student’s paper record.', 'center' ),
				$s( '', 'Corrections', 'An administrator can correct a lesson or override the evaluation; the change is recorded.', 'center' ),
			) ),
			'df_instructors' => array( 'driveflow', 'Instructors (DriveFlow)', 'Add instructors, onboarding, agreements and payouts.', 'admin.php?page=driveflow-pro-instructors', 'driveflow-pro-instructors', array(
				$s( '', 'Instructors', 'Each instructor’s hours, usual car, wage and payment method.', 'center' ),
				$s( '.wrap h1', 'Add an instructor', 'Add the name, phone and e-mail, the working hours and the usual car.', 'bottom' ),
				$s( '', 'Onboarding link', 'Send the instructor their onboarding link: they add their photo, instructor ID and badge, payment details, create their password and sign the employment agreement.', 'center' ),
				$s( '', 'Agreement and payouts', 'Open the signed agreement to print it. Record payouts at the end of each pay period; the history stays with the instructor.', 'center' ),
				$s( '', 'Removing an instructor', 'Set them to inactive. Their past lessons and evaluations stay on record.', 'center' ),
			) ),
			'df_vehicles' => array( 'driveflow', 'Vehicles & Fleet', 'Cars, plates, fuel and maintenance.', 'admin.php?page=driveflow-pro-vehicles', 'driveflow-pro-vehicles', array(
				$s( '', 'Vehicles & Fleet', 'Every training car with its Maryland plate, model and status.', 'center' ),
				$s( '.wrap h1', 'Add a car', 'Plate, model, color and transmission. A car set to <b>maintenance</b> or <b>inactive</b> is not offered for booking.', 'bottom' ),
				$s( '', 'Fuel and incidents', 'After each lesson the instructor reports the fuel level and any incident; the latest report shows on the car.', 'center' ),
			) ),
			'df_hub' => array( 'driveflow', 'DriveFlow hub', 'Shortcuts, booking links and the lobby TV remote.', 'admin.php?page=driveflow-pro-hub', 'driveflow-pro-hub', array(
				$s( '', 'DriveFlow hub', 'Shortcuts to every DriveFlow screen, quick booking links and the remote for the lobby TV.', 'center' ),
				$s( '#df-hub-open-quick-magic', 'Quick links', 'Create a booking link for a student or an onboarding link for an instructor in two clicks.', 'bottom' ),
				$s( '#df-tv-ticker-input', 'Lobby TV', 'Change the announcement ticker, push an alert, or reload the TV screen remotely.', 'top' ),
				$s( '#df-hub-open-guide-btn', 'Operations manual', 'The full DriveFlow manual, one click away.', 'bottom' ),
			) ),
			'df_reports' => array( 'driveflow', 'Reports & Analytics', 'Lessons, instructors and evaluations in numbers.', 'admin.php?page=driveflow-pro-reports', 'driveflow-pro-reports', array(
				$s( '', 'Reports & Analytics', 'Lessons completed, cancelled and upcoming, hours per instructor and recent evaluations for any date range.', 'center' ),
				$s( '.wrap h1', 'Export and print', 'Export to CSV for the accountant, or print the official report on the school letterhead.', 'bottom' ),
			) ),
			'registrations' => array( 'registrations', 'Registrations', 'Find a student, open their contract and signature.', 'admin.php?page=sdsc-registrations', 'sdsc-registrations', array(
				$s( '', 'Registrations', 'Every online sign-up lands here with the student’s details, the course and the signed contract.', 'center' ),
				$s( '.wrap h1', 'This screen', 'The newest registrations are at the top.', 'bottom' ),
				$s( '.wrap table.widefat', 'Students', 'Each row is one registration. Open a row to see the full form and the signature.', 'top' ),
				$s( '', 'Privacy', 'This screen holds personal information. Do not share screenshots or export it outside the school.', 'center' ),
			) ),
			'reports' => array( 'reports', 'Records & Reports', 'Find a student record, print it and file it.', 'admin.php?page=sdsc-reports', 'sdsc-reports', array(
				$s( '', 'Records & Reports', 'Complete student records for printing and filing.', 'center' ),
				$s( '.wrap h1', 'This screen', 'Search or pick a student to open their full record.', 'bottom' ),
				$s( '', 'Highly sensitive', 'Records contain Social Security numbers and signatures. Print only what you need, file paper copies in a locked cabinet, never e-mail or photograph them, and close this screen when you step away.', 'center' ),
			) ),
			'notices' => array( 'notices', 'Banners & alerts', 'Put a notice on the website, plan holiday banners, publish county alerts.', 'admin.php?page=sdsc-notices', 'sdsc-notices', array(
				$s( '', 'Banners & alerts', 'Tell visitors about closures, holidays and news — in English, or English and Spanish.', 'center' ),
				$s( '.wrap .nav-tab-wrapper', 'Three tabs', '<b>Banners</b> you write, the <b>Holiday calendar</b> (US and Maryland days, set up once) and <b>Montgomery County alerts</b> from the National Weather Service.', 'bottom' ),
				$s( '#sdnb-form', 'Write a banner', 'Start from a template (snow closure, holiday…) or write your own. Spanish is optional.', 'right' ),
				$s( '#sdnb-icon', 'Pick a 3D picture', 'Each banner can show an animated 3D icon.', 'auto' ),
				$s( '#sdnb-prev', 'Live preview', 'This is exactly how the bar will look on the site.', 'auto' ),
				$s( '.wrap table.widefat', 'Your banners', 'Start and end dates control when a banner shows. Tick <i>Homepage pop-up</i> for important notices.', 'top' ),
				$s( '', 'County alerts need your OK', 'Weather alerts are fetched every 15 minutes but only appear on the website after someone presses <b>Show on website</b>. They hide themselves when they expire.', 'center' ),
			) ),
			'feed' => array( 'feed', 'Website listing', 'Decide which classes appear on the website.', 'admin.php?page=sdsc-feed', 'sdsc-feed', array(
				$s( '', 'Website listing', 'Controls how upcoming classes are listed on the public site.', 'center' ),
				$s( '.wrap .form-table', 'Settings', 'Choose what the class list shows.', 'right' ),
				$s( '.wrap table.widefat', 'Classes', 'The classes that will appear on the website, in order.', 'top' ),
			) ),
			'mva' => array( 'mva', 'MVA headlines', 'Today’s MVA news and how to turn it into an article.', 'admin.php?page=sdsc-mva', 'sdsc-mva', array(
				$s( '', 'MVA headlines', 'Headlines from the Maryland MVA, checked once a day. Only headlines useful to learners and parents are kept.', 'center' ),
				$s( '.wrap table.widefat', 'Headlines', 'Each headline links to the original MVA page.', 'top' ),
				$s( '.wrap table.widefat .button', 'Make a draft', 'Creates a draft article in <b>Maryland Laws &amp; MVA Updates</b>. Write the explanation in your own words — never copy the MVA text.', 'left' ),
			) ),
			'prices' => array( 'prices', 'Prices', 'Change course and service prices.', 'admin.php?page=sdsc-prices', 'sdsc-prices', array(
				$s( '', 'Prices', 'The prices here are what checkout charges. The website never shows a price that checkout does not use.', 'center' ),
				$s( '.wrap table.widefat', 'Price list', 'Change a price and save. It updates on the website and at checkout together.', 'top' ),
				$s( '.wrap .button-primary', 'Save', 'Nothing changes until you press Save.', 'top' ),
			) ),
			'outreach' => array( 'outreach', 'Celebrations & campaigns', 'Birthday and license-anniversary greetings, and group messages.', 'admin.php?page=sdsc-outreach', 'sdsc-outreach', array(
				$s( '', 'Celebrations & campaigns', 'Greet students on their birthday and on the anniversary of their driver’s license, and send one message to many students — in English or Spanish.', 'center' ),
				$s( '.sdo-tabs', 'Five sections', '<b>Today</b> is who to greet today. <b>Students</b> is where you set the language and license date. <b>Campaigns</b> sends group messages. <b>Message wording</b> holds the English and Spanish texts.', 'bottom' ),
				$s( '.sdo-card .sdo-btn--text, #sdo-birthday', 'Text with one tap', 'On your phone, <b>Text</b> opens Messages with the number and the greeting already written. You only press Send. The e-mail goes out by itself in the morning.', 'bottom' ),
				$s( '.sdo-lang', 'English or Spanish', 'Switch the language of a message before you send it. Each student&rsquo;s usual language is set on the Students tab.', 'left' ),
				$s( '', 'License anniversaries', 'After a student passes the road test, fill in the license date on the Students tab — or tap <b>Ask</b> and they fill it in themselves from a link.', 'center' ),
			) ),
			'discounts' => array( 'discounts', 'Discounts', 'Create a discount and preview it safely.', 'admin.php?page=sdsc-discounts', 'sdsc-discounts', array(
				$s( '', 'Discounts', 'Percentage discounts for a service, for example car rental for the road test.', 'center' ),
				$s( '.wrap table.widefat', 'Discount list', 'Each discount, what it applies to and whether it is live.', 'top' ),
				$s( '', 'Preview first', '<b>Preview</b> opens the page with the discount shown only to you, so you can check it before customers see it.', 'center' ),
			) ),
			'blocklist' => array( 'blocklist', 'Blocked sign-ups', 'Stop spam accounts and see what the bot trap caught.', 'admin.php?page=sdsc-blocklist', 'sdsc-blocklist', array(
				$s( '', 'Blocked sign-ups', 'Addresses and domains listed here cannot create an account on any form of the site.', 'center' ),
				$s( '#sdsc-bl-e', 'Blocked e-mails', 'One address per line. Gmail look-alikes (extra dots, “+” tags) are caught automatically.', 'right' ),
				$s( '#sdsc-bl-d', 'Blocked domains', 'Every address at these domains is refused.', 'right' ),
				$s( '.wrap table.widefat', 'Log', 'Every refused sign-up, including robots caught by the hidden trap. Real visitors never see the trap.', 'top' ),
			) ),
			'api' => array( 'api', 'API connections', 'Where Stripe, Zoom and other keys are stored.', 'admin.php?page=sdsc-api', 'sdsc-api', array(
				$s( '', 'API connections', 'Keys that connect the site to Stripe, Zoom and other services.', 'center' ),
				$s( '.wrap .form-table', 'Keys', 'Paste keys here yourself. Never send a key by e-mail or chat.', 'right' ),
				$s( '.wrap .wp-list-table, .wrap table.widefat', 'Connection test', 'Use Test to check a key works before you rely on it.', 'top' ),
			) ),
			'settings' => array( 'settings', 'Settings', 'General plugin settings.', 'admin.php?page=sdsc-settings', 'sdsc-settings', array(
				$s( '', 'Settings', 'General options for Sam’s School. Change them only if you know what they do.', 'center' ),
				$s( '.wrap .form-table', 'Options', 'Each option has a short description next to it.', 'right' ),
			) ),
			'updates' => array( 'updates', 'What’s new', 'Read the explanations of recent changes.', 'admin.php?page=sdsc-updates', 'sdsc-updates', array(
				$s( '', 'What’s new', 'Every time something changes on the website or in your tools, the explanation appears here.', 'center' ),
				$s( '#sdsc-upd-list', 'Updates', 'Newest first. Items you have not read yet are marked <b>New</b>, and the menu shows a red counter until you open this page.', 'top' ),
				$s( '#sdsc-upd-form', 'Write an update', 'Administrators: write what changed, choose who it is for and, if you like, e-mail them a copy.', 'bottom' ),
			) ),
			'email' => array( 'email', 'Email', 'How school e-mails are sent and checked.', 'admin.php?page=sdsc-email', 'sdsc-email', array(
				$s( '', 'Email', 'Class and order e-mails come from info@, passwords and notices from noreply@. Every e-mail gets the school layout and signature.', 'center' ),
				$s( '#sdsc-mail-checks', 'Delivery checklist', 'All green means the domain is set up so Gmail and Outlook trust the school’s e-mails.', 'bottom' ),
				$s( '#sdsc-mail-boxes', 'Mailboxes', 'Type each mailbox password once. It is stored encrypted and never shown again.', 'top' ),
				$s( '.sdsc-mail-test', 'Test', 'Send yourself a test after every change.', 'top' ),
			) ),
			'team' => array( 'team', 'Team access', 'Decide what instructors and front-desk staff can open.', 'admin.php?page=sdsc-team', 'sdsc-team', array(
				$s( '', 'Team access', 'Give each role exactly the screens it needs — nothing more.', 'center' ),
				$s( '.sdsc-team-roles', 'Roles', 'How many people have each role. <b>View</b> lists them.', 'bottom' ),
				$s( '#sdsc-team-matrix', 'Who can open what', 'Switch a screen on or off for Instructors or Front Desk. People only see the screens — and tours — they are allowed.', 'top' ),
				$s( '.sdsc-team-lock', 'Admin only', 'Keys, settings and this screen always stay with administrators.', 'left' ),
				$s( '.wrap #submit', 'Save', 'Changes apply on the person’s next page load.', 'top' ),
			) ),
			'blog' => array( 'edit_posts', 'Driving Tips articles', 'Write an article and file it under the right topic.', 'edit.php', 'edit-post', array(
				$s( '', 'Driving Tips & News', 'Articles here appear on the website’s Driving Tips page, grouped by topic.', 'center' ),
				$s( '.wrap .page-title-action', 'New article', 'Start a new article here.', 'bottom' ),
				$s( '#cat', 'Topics', 'Filter the list by topic: Permit &amp; License, Road Test &amp; Skills, Maryland Laws, Safe Driving and more.', 'bottom' ),
				$s( '.wp-list-table th.column-sdsc_level', 'Level and featured', 'Shows the level you chose and a star on the featured article.', 'bottom' ),
				$s( '', 'In the editor', 'Pick one topic under <b>Categories</b>, then use <b>Article details</b> to set the level, the language and whether it is the featured article. Reading time is calculated for you.', 'center' ),
			) ),
		);
	}

	/* ------------------------------------------------------------ website (front-end) tours */

	/** key => [title, summary, steps]. Shown on the student and instructor pages of the website. */
	public static function front_tours() {
		$s = function ( $target, $title, $content, $place = 'auto' ) {
			return array( 'target' => $target, 'title' => $title, 'content' => $content, 'placement' => $place );
		};
		return array(
			'student_lessons' => array( 'My driving lessons (students)', 'Where students see their lessons, confirm, add them to their calendar and see fees.', array(
				$s( '', 'Your driving lessons', 'Everything about your classes with Sam’s Driving School is on this page: your Zoom classes, your behind-the-wheel lessons, and any fee or refund.', 'center' ),
				$s( '.sdl-front > .sdl-m', 'The cancellation rule', 'You can cancel or change a lesson for free until the deadline shown on each lesson. Later than that, a missed lesson, or coming without your learner’s permit carries the fee.', 'bottom' ),
				$s( '.sdl-front a.sdl-pay', 'All classes in your calendar', 'Tap once to add every class and lesson to your iPhone, Google or Outlook calendar, with an alert one hour before. Changes update by themselves.', 'bottom' ),
				$s( '.sdl-front .sdl-pill', 'Zoom absences', 'Session 1 is mandatory. After it, up to 4 absences are allowed — always tell the school before you miss a class.', 'left' ),
				$s( '.sdl-front .sdl-note', 'Confirm with one tap', 'Tap <b>Confirm I’ll be there</b> — or <b>I can’t make it</b> so the school knows. The same buttons are in your e-mails and texts.', 'top' ),
				$s( '.sdl-front .sdl-hist', 'Your lessons and fees', 'Each lesson with its date, status and any fee. A waived fee says so — there is nothing to pay.', 'top' ),
				$s( '', 'Need to change something?', 'Call or text the school before the free-cancellation deadline. Bring your valid learner’s permit to every behind-the-wheel lesson.', 'center' ),
			) ),
			'student_booking' => array( 'Book my lessons (students)', 'How students book their own behind-the-wheel lessons from their private link.', array(
				$s( '', 'Book your driving lessons', 'This private link lets you choose your own 2-hour behind-the-wheel lessons. Do not share it — it is yours and it expires.', 'center' ),
				$s( '#df-book-instructor', '1 · Instructor', 'Choose your instructor — for example one who speaks Spanish or Farsi.', 'bottom' ),
				$s( '#df-book-date', '2 · Day', 'Choose the day you would like to drive.', 'bottom' ),
				$s( '#df-slots-container', 'Pick a time', 'Only times when an instructor and a car are free are shown.', 'top' ),
				$s( '#df-confirm-booking-btn', 'Confirm', 'Your lesson is booked at once. You get an e-mail (and a text from the school) with the place, what to bring and the free-cancellation deadline.', 'top' ),
				$s( '#df-student-sessions-tbody', 'Your lessons', 'Every lesson you have booked, with the instructor and the car.', 'top' ),
			) ),
			'student_portal' => array( 'Lesson scorecards (students)', 'Where students see their lessons and the instructor’s scorecards.', array(
				$s( '', 'Your lessons and scorecards', 'After each behind-the-wheel lesson your instructor fills in a scorecard. You see the skills you have mastered and the instructor’s advice for the next lesson here.', 'center' ),
			) ),
			'instructor_app' => array( 'Instructor lesson app', 'What instructors do on their phone before, during and after every lesson.', array(
				$s( '', 'Your lesson app', 'Use this page on your phone or tablet for every behind-the-wheel lesson. Sign in once; your name and licence number are filled in for you.', 'center' ),
				$s( '#sam-today-sessions-wrap', 'Today’s lessons', 'Your lessons for today. Tap one to start — the student, lesson number and car are filled in.', 'bottom' ),
				$s( '#sam-student-name', 'Student', 'Check it is the right student. Ask to see the valid learner’s permit before you drive. No permit = no lesson: tell the office.', 'bottom' ),
				$s( '#sam-timer-btn', 'Lesson timer', 'Start when you drive off and stop at the end; the exact time behind the wheel is recorded.', 'bottom' ),
				$s( '#sam-camera-btn', 'Selfie', 'A quick photo with the student confirms the lesson took place.', 'bottom' ),
				$s( '#mvaInCarEvalForm', 'Evaluation', 'Rate every skill. Nothing is pre-selected — leave no row empty. Add advice for the next lesson.', 'top' ),
				$s( '#sam-fuel-dial', 'Fuel and car check', 'Set the fuel level after the lesson. Below 1/4 tank, refuel with the student (it is part of the curriculum) and enter the gallons.', 'top' ),
				$s( '#sam-incident-yes-pill', 'Incidents', 'Anything happened to the car or on the road? Say Yes and describe it — the office sees it at once.', 'top' ),
				$s( '#instructorSig', 'Student signature', 'The student signs with a finger on your screen.', 'top' ),
				$s( '#sam-submit-btn', 'Submit', 'The lesson closes as completed, the student gets the scorecard, and the office record is updated. Running late, sick or a car problem? Call the office — never cancel a lesson by yourself.', 'top' ),
			) ),
			'instructor_onboarding' => array( 'Instructor onboarding', 'What a new instructor fills in from their onboarding link.', array(
				$s( '', 'Welcome to the team', 'Fill this form once from your private link. It takes about ten minutes.', 'center' ),
				$s( '#df-instructor-photo-input', 'Photo, ID and badge', 'A clear photo of you, your instructor ID card and your badge.', 'bottom' ),
				$s( '#df-method-fields-zelle', 'How you are paid', 'Choose Zelle, direct deposit, check or cash and enter the details.', 'top' ),
				$s( '#df-onboarding-pwd', 'Your password', 'Create the password you will use to sign in to the lesson app.', 'top' ),
				$s( '#df-signature-pad', 'The agreement', 'Read the employment agreement, tick that you accept and sign with your finger.', 'top' ),
				$s( '#df-submit-onboarding-btn', 'Send', 'The school receives your details and signed agreement. You can then sign in to the lesson app.', 'top' ),
			) ),
		);
	}

	/** Which website tour belongs on the page being shown. */
	public static function front_key() {
		$df = (array) get_option( 'driveflow_pro_settings', array() );
		$map = array( 'magic_booking_page_id' => 'student_booking', 'student_portal_page_id' => 'student_portal', 'instructor_page_id' => 'instructor_app', 'instructor_onboarding_page_id' => 'instructor_onboarding' );
		foreach ( $map as $opt => $k ) {
			if ( ! empty( $df[ $opt ] ) && is_page( (int) $df[ $opt ] ) ) { return $k; }
		}
		if ( is_user_logged_in() ) {
			if ( class_exists( 'SDSC_Lessons' ) && SDSC_Lessons::TAB === get_query_var( 'tutor_dashboard_page' ) ) { return 'student_lessons'; }
			$p = get_post();
			if ( is_singular() && $p && has_shortcode( (string) $p->post_content, 'sdsc_my_lessons' ) ) { return 'student_lessons'; }
		}
		return '';
	}

	/** Website page of each tour, for the staff list. */
	public static function front_url( $k ) {
		$df  = (array) get_option( 'driveflow_pro_settings', array() );
		$ids = array( 'student_booking' => 'magic_booking_page_id', 'student_portal' => 'student_portal_page_id', 'instructor_app' => 'instructor_page_id', 'instructor_onboarding' => 'instructor_onboarding_page_id' );
		if ( 'student_lessons' === $k ) { return class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::my_url() : ''; }
		return ! empty( $df[ $ids[ $k ] ?? '' ] ) ? get_permalink( (int) $df[ $ids[ $k ] ] ) : '';
	}

	public static function front_assets() {
		$key = self::front_key();
		if ( ! $key ) { return; }
		$t    = self::front_tours()[ $key ];
		$auto = isset( $_GET['sdsc_tour'] ) && sanitize_key( wp_unslash( $_GET['sdsc_tour'] ) ) === $key; // phpcs:ignore WordPress.Security.NonceVerification
		wp_enqueue_style( 'sdsc-admin-ui', SDSC_URL . 'assets/admin-ui.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-admin-ui', SDSC_URL . 'assets/admin-ui.js', array(), SDSC_VERSION, true );
		wp_localize_script( 'sdsc-admin-ui', 'SDSC_TOUR', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sdsc_tour' ),
			'key'   => $key,
			'auto'  => $auto ? 1 : 0,
			'seen'  => is_user_logged_in() && in_array( $key, self::seen(), true ) ? 1 : 0,
			'title' => $t[0],
			'steps' => $t[2],
			'list'  => '',
		) );
	}

	/** Tours this person may take. */
	public static function mine() {
		if ( ! SDSC_Access::is_staff() ) { return array(); }
		$out = array();
		foreach ( self::tours() as $k => $t ) {
			$ok = isset( SDSC_Access::features()[ $t[0] ] ) ? SDSC_Access::can( $t[0] ) : current_user_can( $t[0] );
			if ( $ok ) { $out[ $k ] = $t; }
		}
		return $out;
	}

	/** Tour for the screen being shown, if any. */
	protected static function current_key() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$want   = isset( $_GET['sdsc_tour'] ) ? sanitize_key( wp_unslash( $_GET['sdsc_tour'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$mine   = self::mine();
		$match  = function ( $t ) use ( $page, $screen ) { return ( $page && $page === $t[4] ) || ( ! $page && $screen && $screen->id === $t[4] ); };
		if ( $want && isset( $mine[ $want ] ) && $match( $mine[ $want ] ) ) { return $want; }
		foreach ( $mine as $k => $t ) {
			if ( $match( $t ) ) { return $k; }
		}
		return '';
	}

	/** Steps with "__groups__" expanded into one step per menu group the person can open. */
	protected static function steps( $key ) {
		$t   = self::tours()[ $key ];
		$out = array();
		foreach ( $t[5] as $st ) {
			if ( '__groups__' !== $st ) { $out[] = $st; continue; }
			$by = array();
			foreach ( SDSC_Access::mine() as $f ) { $by[ $f[1] ][] = $f[0]; }
			foreach ( SDSC_Access::groups() as $g => $label ) {
				if ( empty( $by[ $g ] ) || 'help' === $g ) { continue; }
				$out[] = array(
					'target'    => '#adminmenu .sdsc-menu-h[data-group="' . $g . '"]',
					'title'     => $label,
					'content'   => esc_html( implode( ' · ', $by[ $g ] ) ),
					'placement' => 'right',
				);
			}
		}
		return $out;
	}

	protected static function seen() {
		$s = get_user_meta( get_current_user_id(), self::META, true );
		return is_array( $s ) ? $s : array();
	}

	/* ------------------------------------------------------------ admin */

	public static function assets() {
		if ( ! SDSC_Access::is_staff() ) { return; }
		$key  = self::current_key();
		$auto = isset( $_GET['sdsc_tour'] ) ? sanitize_key( wp_unslash( $_GET['sdsc_tour'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$data = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sdsc_tour' ),
			'key'   => $key,
			'auto'  => $auto && $auto === $key ? 1 : 0,
			'seen'  => $key && in_array( $key, self::seen(), true ) ? 1 : 0,
			'title' => $key ? self::tours()[ $key ][1] : '',
			'steps' => $key ? self::steps( $key ) : array(),
			'list'  => admin_url( 'admin.php?page=sdsc-tours' ),
		);
		wp_localize_script( 'sdsc-admin-ui', 'SDSC_TOUR', $data );
	}

	public static function admin_bar( $bar ) {
		if ( ! is_admin() || ! SDSC_Access::is_staff() ) { return; }
		$bar->add_node( array(
			'id'    => 'sdsc-help',
			'title' => '<span class="ab-icon dashicons dashicons-sos" aria-hidden="true"></span><span class="ab-label">Help &amp; tours</span>',
			'href'  => admin_url( 'admin.php?page=sdsc-tours' ),
		) );
		$key = self::current_key();
		if ( $key ) {
			$bar->add_node( array( 'parent' => 'sdsc-help', 'id' => 'sdsc-help-here', 'title' => 'Tour this screen', 'href' => '#sdsc-tour', 'meta' => array( 'class' => 'sdsc-tour-start' ) ) );
		}
		$bar->add_node( array( 'parent' => 'sdsc-help', 'id' => 'sdsc-help-all', 'title' => 'All guided tours', 'href' => admin_url( 'admin.php?page=sdsc-tours' ) ) );
	}

	public static function ajax_seen() {
		check_ajax_referer( 'sdsc_tour' );
		$k = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( $k && ( isset( self::tours()[ $k ] ) || isset( self::front_tours()[ $k ] ) ) ) {
			$s = self::seen();
			if ( ! in_array( $k, $s, true ) ) { $s[] = $k; update_user_meta( get_current_user_id(), self::META, $s ); }
		}
		wp_send_json_success();
	}

	public static function page() {
		if ( ! SDSC_Access::is_staff() ) { wp_die( 'Not allowed.' ); }
		$seen = self::seen();
		$u    = wp_get_current_user();
		$r    = (array) $u->roles;
		$role = current_user_can( 'manage_options' ) ? 'admin' : ( in_array( SDSC_Access::MANAGER, $r, true ) ? 'manager' : ( in_array( SDSC_Access::FRONTDESK, $r, true ) ? 'frontdesk' : 'instructor' ) );
		?>
		<div class="wrap sdsc-tours">
			<h1>Guided tours</h1>
			<p class="sdsc-lead">Hi <?php echo esc_html( $u->display_name ); ?> — you are signed in as <span class="sdsc-role-chip sdsc-role-chip--<?php echo esc_attr( $role ); ?>"><?php echo esc_html( SDSC_Access::role_label( $role ) ); ?></span>. Pick a screen and we&rsquo;ll walk you through it. You can replay any tour from <b>Help &amp; tours</b> in the top bar.</p>
			<div class="sdsc-tour-grid" id="sdsc-tour-list">
				<?php foreach ( self::mine() as $k => $t ) : $done = in_array( $k, $seen, true ); ?>
					<a class="sdsc-tour-card<?php echo 'start' === $k ? ' is-start' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'sdsc_tour', $k, admin_url( $t[3] ) ) ); ?>">
						<span class="sdsc-tour-card-t"><?php echo esc_html( $t[1] ); ?></span>
						<span class="sdsc-tour-card-d"><?php echo esc_html( $t[2] ); ?></span>
						<span class="sdsc-tour-card-f"><span><?php echo count( 'start' === $k ? self::steps( $k ) : $t[5] ); ?> steps</span><?php echo $done ? '<span class="sdsc-tour-done">&#10003; Seen</span>' : '<span class="sdsc-tour-new">New</span>'; ?><b>Start &rarr;</b></span>
					</a>
				<?php endforeach; ?>
			</div>
			<h2 class="sdsc-tours-h2">Tours on the website</h2>
			<p class="sdsc-lead">Students and instructors see these on their own pages (the green <b>Take the tour</b> button). Open one to see exactly what they see.</p>
			<div class="sdsc-tour-grid">
				<?php foreach ( self::front_tours() as $k => $t ) : $url = self::front_url( $k ); if ( ! $url ) { continue; } ?>
					<a class="sdsc-tour-card" href="<?php echo esc_url( add_query_arg( 'sdsc_tour', $k, $url ) ); ?>" target="_blank" rel="noopener">
						<span class="sdsc-tour-card-t"><?php echo esc_html( $t[0] ); ?></span>
						<span class="sdsc-tour-card-d"><?php echo esc_html( $t[1] ); ?></span>
						<span class="sdsc-tour-card-f"><span><?php echo count( $t[2] ); ?> steps</span><b>Open &rarr;</b></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
