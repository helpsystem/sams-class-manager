<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Guided tours for every Sam's School screen.
 *
 *  - Help → Guided tours lists the tours of the screens the person can open (role-aware), grouped like the Workspace menu.
 *  - Every screen in the dashboard has a "How this screen works" button next to its title; it has a yellow dot until
 *    the person has taken that tour. The tour runs on the dashboard and points into the screen's frame.
 *  - Website pages (students' lessons, booking, in-car app, onboarding) and the staff dashboard tabs (Workspace,
 *    Zoom attendance, Reports & printing, Messages) have their own tours.
 *  - Steps point at parts of the screen; optional steps are left out when that part is not shown right now.
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

	/**
	 * key => [feature key or capability, title, one-line summary, admin URL, screen match, steps]
	 *
	 * Step targets: a CSS selector inside the screen; "top:" = the dashboard around the screen; "sel@@text" = only
	 * elements containing that text. A step marked optional (5th argument true) is left out when its part is not on
	 * the screen right now (an empty list, another tab), so the tour never points at nothing.
	 */
	public static function tours() {
		$s = function ( $target, $title, $content, $place = 'auto', $opt = false ) {
			return array( 'target' => $target, 'title' => $title, 'content' => $content, 'placement' => $place, 'skip' => $opt ? 1 : 0 );
		};
		$o = function ( $target, $title, $content, $place = 'auto' ) use ( $s ) { return $s( $target, $title, $content, $place, true ); };
		return array(
			/* ---------------------------------------------------------------- start & daily paths */
			'start' => array( 'tours', 'Welcome to your workspace', 'Where everything lives: the Workspace menu, quick actions, the sidebar, alerts and messages.', 'admin.php?page=sdsc-tours', 'sdsc-tours', array(
				$s( '', 'Welcome', 'Everything for running the school is in this dashboard. You only see the screens your role may open. Move with the arrow keys or the buttons; Esc closes the tour at any time.', 'center' ),
				$o( 'top:.sws-menu', 'The Workspace menu', 'Every screen, sorted into groups: <b>Lessons &amp; attendance</b>, <b>Students &amp; classes</b>, <b>Instructors &amp; cars</b>, <b>Money &amp; accounting</b>, <b>Messages</b>, <b>Reports</b>, <b>Website</b>, <b>Setup</b> and <b>Help</b>.', 'bottom' ),
				$o( 'top:.sws-quick', 'Quick actions', 'One click to the jobs you do most: book a lesson, add a class, add a student, take Zoom attendance, open reports.', 'bottom' ),
				$o( 'top:.tutor-dashboard-left-menu, .tutor-dashboard-menu', 'The sidebar', 'Your everyday screens are also on the left: lesson calendar, students, registrations, instructors, accounting, messages, Zoom attendance and reports.', 'right' ),
				$o( 'top:.sdk-btn--bell', 'Alerts', 'The bell counts what changed for you: new registrations and payments, lessons booked, moved or cancelled, evaluations with an incident or low fuel. Click one to open it.', 'left' ),
				$o( 'top:.sdk-btn--chat', 'Messages', 'Conversations with students and instructors. The office sees every conversation with the school.', 'left' ),
				$o( '#sdsc-tour-list', 'A tour for every screen', 'Each card opens that screen and walks you through it. A yellow dot means you have not taken that tour yet.', 'top' ),
				$s( '', 'Help is on every screen', 'At the top of each screen, next to its title, the <b>How this screen works</b> button replays its tour whenever you need it.', 'center' ),
			) ),
			'path_manager' => array( 'refunds', 'School manager: the day, step by step', 'Which screen to open, in what order, and what to check — the routine that keeps every class and lesson on time.', 'admin.php?page=sdsc-tours', 'sdsc-tours', array(
				$s( '', 'The school day in 11 steps', 'Each step names a screen in the <b>Workspace</b> menu. Every screen also has its own tour (the <b>How this screen works</b> button next to its title).', 'center' ),
				$s( '', '1 · What’s new', '<b>Help → What’s new</b> explains every change to the website and your tools. Read new items first.', 'center' ),
				$s( '', '2 · Lessons overview', '<b>Lessons &amp; attendance → Lessons overview</b>: today’s numbers, booking links for students and onboarding links for instructors.', 'center' ),
				$s( '', '3 · Lesson calendar', 'Check today and tomorrow: every lesson has a student, an instructor and a car, and nothing shows a red collision. Block instructor time off and car maintenance here.', 'center' ),
				$s( '', '4 · BTW lessons & fees', 'Close yesterday’s lessons: <b>Completed</b>, <b>No-show</b> or <b>No permit</b> (the last two add the late-cancellation fee). Waive a fee only with a reason.', 'center' ),
				$s( '', '5 · Texts to send', 'On the school iPhone, send every text that is due — confirmations, reminders, today’s lessons, Zoom reminders, greetings — until the list is empty.', 'center' ),
				$s( '', '6 · Zoom attendance', 'For each Zoom class today, the instructor (or you) submits the sheet after class. <b>Class attendance (sheet)</b> shows the whole class at once.', 'center' ),
				$s( '', '7 · Registrations', 'New sign-ups arrive with the signed contract. Students who also drive with us get a profile in <b>Students &amp; lesson credits</b> and their booking link.', 'center' ),
				$s( '', '8 · Money', '<b>Refunds</b> by the refund policy, <b>Lesson accounting</b> for instructor hours and payouts, <b>Discounts &amp; offers</b> for codes and the early-bird price.', 'center' ),
				$s( '', '9 · Instructors & cars', 'Send onboarding links, check signed agreements, record payouts. Keep car plates and status current in <b>Cars</b>.', 'center' ),
				$s( '', '10 · Website', '<b>Banners &amp; alerts</b> for closures and holidays, <b>Website listing</b> for the class signs, <b>Lobby TV</b> for the screen in the office. The website never shows a price that checkout does not charge.', 'center' ),
				$s( '', '11 · Reports', '<b>Reports &amp; printing</b>: rosters, attendance, registrations, payments, lessons and fees — print or download for the file.', 'center' ),
			) ),
			'path_frontdesk' => array( 'lessons', 'Front desk: booking a lesson from a text', 'From the first text of a student to the confirmation — the exact order to follow.', 'admin.php?page=sdsc-tours', 'sdsc-tours', array(
				$s( '', 'A student texts to schedule', 'Ask the same three things every time: the <b>full name</b>, <b>do you have your learner’s permit?</b> and <b>how many practice hours are logged?</b>', 'center' ),
				$s( '', '1 · Is the student here already?', 'Open <b>Students &amp; lesson credits</b> and search the name or phone. New student? Press <b>Add New Student</b> with phone, e-mail, package and number of lessons.', 'center' ),
				$s( '', '2 · Find free times', 'Open the <b>Lesson calendar</b>. Filter by instructor when the student needs a language (English, Spanish, Farsi). Empty cells are free 2-hour slots.', 'center' ),
				$s( '', '3 · Offer two times', 'Text two options, for example “Tuesday 1:00 or Thursday 1:00?”. Or send the student’s private <b>booking link</b> (Short Link on Students &amp; lesson credits) and let them pick.', 'center' ),
				$s( '', '4 · Book it', 'Double-click the slot (or press <b>+ Schedule Lesson</b>), choose student, instructor and car, and save. A red warning means the instructor or car is already busy or blocked.', 'center' ),
				$s( '', '5 · The confirmation goes out by itself', 'The student gets the confirmation e-mail with the place, what to bring and the free-cancellation deadline. The same text waits in <b>Texts to send</b> — send it from the school phone.', 'center' ),
				$s( '', '6 · Changes', 'Drag the lesson to its new time on the calendar. The student and the instructor are told, and the reminders start again for the new time.', 'center' ),
				$s( '', '7 · Cancellations', 'Open the lesson in <b>BTW lessons &amp; fees</b> and press <b>Cancel</b>, entering when the student told you. In time: no fee. Late: the fee is added (you can waive it with a reason). The lesson is cancelled on the calendar as well.', 'center' ),
				$s( '', '8 · Every morning', 'Work through <b>Texts to send</b>: today’s lessons, reminders for students who have not confirmed, Zoom class reminders.', 'center' ),
			) ),

			/* ---------------------------------------------------------------- lessons & attendance */
			'df_hub' => array( 'driveflow', 'Lessons overview', 'Today’s numbers, booking and onboarding links, and the lobby TV remote.', 'admin.php?page=driveflow-pro-hub', 'driveflow-pro-hub', array(
				$s( '', 'Lessons overview', 'The control room for behind-the-wheel lessons: the numbers for today, quick links, and the remote for the lobby TV.', 'center' ),
				$s( '.df-header-actions', 'Shortcuts', '<b>Operations Guide</b> is the full manual. The other buttons open the calendar, the sessions log and the student portal, and <b>Issue Magic Link</b> makes a private link in two clicks.', 'bottom' ),
				$s( '.df-stats-grid', 'The numbers', 'Driving sessions (active and completed), students, certified instructors, cars and the short links that are still valid. They update on every visit.', 'bottom' ),
				$s( '#df-hub-student-select', 'Booking link for a student', 'Pick a student and press <b>Issue Student Booking Link</b>. The student books their own lessons from free slots with it.', 'bottom' ),
				$s( '#df-hub-instructor-select', 'Onboarding link for an instructor', 'Pick a new instructor and press <b>Issue Instructor Onboarding Link</b>: they upload ID and badge, payment details, create a password and sign the agreement.', 'bottom' ),
				$s( '.df-hub-tv-commander', 'Lobby TV remote', 'Change what the lobby TV shows, push an urgent banner, edit the ticker or refresh the TV. The <b>Lobby TV</b> screen (Website group) does the same with a live preview.', 'top' ),
				$s( '', 'Below: every module', 'The cards at the bottom open each part — calendar, sessions, students, instructors, cars, reports — the same screens as in the Workspace menu.', 'center' ),
			) ),
			'df_calendar' => array( 'driveflow', 'Lesson calendar', 'Book, move, assign and block behind-the-wheel lessons.', 'admin.php?page=driveflow-pro-calendar', 'driveflow-pro-calendar', array(
				$s( '', 'Lesson calendar', 'The one place where behind-the-wheel lessons are booked and moved. Confirmations, reminders, texts, the lobby TV and the in-car app all follow from here.', 'center' ),
				$s( '.df-header-actions .df-btn-primary', 'Schedule a lesson', 'Opens the booking form: student, instructor, car, start and end. Collisions with another lesson or a block are checked before saving.', 'bottom' ),
				$s( '#df-open-block-modal-btn', 'Block time', 'Instructor time off, a car in maintenance, or a whole-school closure. Blocked time cannot be booked.', 'bottom' ),
				$s( '#df-cal-view-toggle', 'Three views', '<b>2-Hour Slots (Week)</b> — the week in lesson blocks. <b>Fleet Vehicles (Day)</b> — one column per car. <b>Instructors (Day)</b> — one column per instructor.', 'bottom' ),
				$s( '#df-cal-today-btn', 'Move through the weeks', '<b>← Previous</b>, <b>Current</b> and <b>Next →</b>. The date range is shown next to the buttons.', 'bottom' ),
				$s( '#df-cal-instructor-filter', 'Filter', 'Show one instructor or one car only — for example to find a slot with a Spanish- or Farsi-speaking instructor. <b>Sync</b> reloads the calendar.', 'bottom' ),
				$s( '#df-assign-tray', 'Assign by dragging', 'Drag a student, instructor or car chip onto a lesson card to assign it. Type in <b>Filter chips</b> to find one quickly.', 'bottom' ),
				$s( '.df-legend-item', 'Colours', 'Blue upcoming · dark green on the road · green completed · red cancelled or blocked.', 'bottom' ),
				$s( '#df-calendar-board', 'Book and move', '<b>Double-click</b> an empty slot to book a 2-hour lesson. <b>Drag</b> a lesson to move it — the student and instructor are told by themselves. Click a lesson to open it.', 'top' ),
				$s( '#df-tz-pill', 'Time zone', 'All times are school time (New York).', 'bottom' ),
			) ),
			'df_records' => array( 'driveflow', 'Evaluations & records', 'Every lesson with its evaluation, proof photo, signatures and notes.', 'admin.php?page=driveflow-pro-records', 'driveflow-pro-records', array(
				$s( '', 'Evaluations & records', 'The register of every behind-the-wheel lesson: upcoming, on the road, completed and cancelled — with the instructor’s evaluation.', 'center' ),
				$s( '.df-header-actions', 'Add, export and print', '<b>Add New Session</b> books a lesson from here. <b>Export CSV / Excel</b> and <b>Print Log</b> give the whole register for the file or the accountant.', 'bottom' ),
				$s( '.df-stats-grid', 'At a glance', 'Total sessions, lessons on the road right now and upcoming ones.', 'bottom' ),
				$s( '.df-filters-form', 'Find a lesson', 'Search a student, instructor or plate, and filter by status and dates. Then <b>Apply Filter</b>.', 'bottom' ),
				$s( 'table.df-table', 'The register', 'One row per lesson: student, instructor, car, lesson number, time and status.', 'top' ),
				$o( 'table.df-table tbody tr td:last-child', 'Actions', '<b>Proof &amp; Skills</b> shows the evaluation, photo and signatures. <b>Edit</b> corrects a lesson, <b>Email</b> resends the scorecard, <b>Start</b> opens it in the in-car app. Delete only real mistakes — the record is the school’s proof of training.', 'left' ),
			) ),
			'lessons' => array( 'lessons', 'BTW lessons & fees', 'Lesson outcomes, confirmations, the cancellation deadline, fees and waivers.', 'admin.php?page=sdsc-lessons', 'sdsc-lessons', array(
				$s( '', 'Behind-the-wheel lessons & fees', 'Every lesson booked on the Lesson calendar appears here within seconds. This screen applies the school policy: confirmations, reminders, the free-cancellation deadline, the fee and waivers.', 'center' ),
				$s( '.sdo-tabs', 'Sections', '<b>Schedule</b> — lessons by day. <b>Fees</b> — what is due, paid or waived. <b>Student history</b> — one student’s lessons and fees. <b>Settings</b> — fee, hours, meeting place and directions. The last tab opens the Lesson calendar.', 'bottom' ),
				$s( '.sdo-actions .sdo-btn', 'Book a lesson', 'Opens the Lesson calendar to book.', 'bottom' ),
				$o( '.sdo-sec .sdl-act', 'How did it go?', 'Lessons that have ended wait here for their outcome. <b>Completed</b> closes it (the instructor’s evaluation does this by itself). <b>No-show</b> or <b>No permit</b> adds the fee.', 'left' ),
				$o( '.sdl-row', 'One lesson', 'Time, student, lesson number and instructor.', 'bottom' ),
				$o( '.sdl-chips', 'Status chips', 'Booked or done, whether the confirmation e-mail went out, the student’s answer (<b>I’ll be there</b> / <b>I can’t make it</b>) and until when they can cancel for free.', 'bottom' ),
				$o( '.sdl-row .sdo-btn@@Open', 'Open', 'Opens the lesson: resend the confirmation, cancel (enter when the student told you), mark the outcome.', 'left' ),
				$s( '', 'Cancelling', 'Before the deadline: no fee and a cancellation notice. After it: the fee is added. Cancelling here also cancels the lesson on the calendar. A lesson cancelled directly on the calendar late also gets the fee — waive it if the school cancelled.', 'center' ),
				$s( '', 'Fees', 'On <b>Fees</b>: <b>Waive</b> needs a short reason (the student is told there is nothing to pay). <b>Mark paid</b> for cash or card at the office. Online payments close the fee by themselves.', 'center' ),
			) ),
			'attendance' => array( 'attendance', 'Class attendance (sheet)', 'The whole attendance sheet of a Zoom class, and the absence rule.', 'admin.php?page=sdsc-attendance', 'sdsc-attendance', array(
				$s( '', 'Class attendance', 'One sheet per Zoom class: every student, every session. Usually the instructor submits each session on <b>Zoom attendance</b> right after class; this sheet shows and corrects the whole class.', 'center' ),
				$s( '.sdo-filter', 'Pick the class', 'Choose the class by name and start date.', 'bottom' ),
				$s( '.sdo-lead', 'The marks', '✓ / ✗ is the student’s own answer to the reminder. Set <b>P</b> present, <b>L</b> late, <b>E</b> left early or <b>A</b> absent.', 'bottom' ),
				$o( 'table.widefat', 'The sheet', 'One row per student, one column per session with its date. <b>Absences</b> counts against the limit.', 'top' ),
				$o( 'button.sdo-btn@@Save attendance', 'Save', 'Nothing is kept until you press <b>Save attendance</b>.', 'top' ),
				$s( '', 'The rules', 'Session 1 is mandatory (MVA). After it, up to 4 absences are allowed and the student must tell the school. The counter warns before a student goes over.', 'center' ),
				$s( '', 'No timetable?', 'If a class shows no sessions, open the class (Students &amp; classes → Classes) and fill <b>Timetable &amp; calendar</b>: days, times and number of sessions.', 'center' ),
			) ),

			/* ---------------------------------------------------------------- students & classes */
			'df_students' => array( 'driveflow', 'Students & lesson credits', 'Behind-the-wheel students, packages, lessons left, booking links and top-ups.', 'admin.php?page=driveflow-pro-students', 'driveflow-pro-students', array(
				$s( '', 'Students & lesson credits', 'Every behind-the-wheel student with their package, lessons used and lessons left. A student keeps one profile even when they drive with different instructors.', 'center' ),
				$s( '.df-header-actions .df-btn-primary', 'Add a student', 'Name, mobile, e-mail (confirmations go there), permit number, package and number of lessons.', 'bottom' ),
				$s( '.df-stats-grid', 'At a glance', 'All enrolled students, and how many still have lessons left.', 'bottom' ),
				$s( '.df-filters-form', 'Search', 'Find a student by name, phone or e-mail.', 'bottom' ),
				$s( 'table.df-table', 'The list', 'Contact, permit, package and the progress bar: lessons done of the total, and how many are left.', 'top' ),
				$o( 'table.df-table button@@Short Link', 'Booking link', '<b>Short Link</b> makes the student’s private booking link (choose how long it stays valid; e-mail it or copy it). <b>Copy Link</b> copies the current one.', 'left' ),
				$o( 'table.df-table button@@Profile', 'Profile', 'Every lesson with each instructor, the scorecards and the credit ledger.', 'left' ),
				$o( 'table.df-table button@@Top-up', 'Top-up', 'Add lessons when the student buys more. Every change is kept in the ledger. <b>Edit</b> corrects contact details.', 'left' ),
			) ),
			'classes' => array( 'edit_posts', 'Classes', 'Add or edit a class: dates, price, course code, Zoom link, timetable, instructor and who can see it.', 'edit.php?post_type=sdsc_class', 'edit-sdsc_class', array(
				$s( '', 'Classes', 'Every Zoom class (Driver’s Education Program) with its dates, price and students. What you enter here is what the website, registration form and checkout use.', 'center' ),
				$o( '.page-title-action', 'Add a class', 'Starts a new class. You can also use <b>＋ New class</b> in the quick actions.', 'bottom' ),
				$o( '.wp-list-table', 'All classes', 'Course code, dates, Zoom account, registrations (paid of total) and status. Click a name to edit the class.', 'top' ),
				$o( '#sdsc_class_details', 'Class details', 'Course code, start and end, days and times as students should read them, seats, price, Zoom account and link. The price is what checkout charges.', 'top' ),
				$o( '#sdsc-visibility', 'Course code and visibility', 'Give the class the next real course number with one tick, or hide it from the website lists (staff tests, private groups). The direct registration link works even for a hidden class.', 'left' ),
				$o( '#sdsc-sched', 'Timetable & calendar', 'Class days, times and number of sessions. The sessions go into every student’s calendar, drive the reminders and build the attendance sheet. Choose the <b>Zoom instructor</b> who takes attendance.', 'top' ),
				$o( '#sdsc_class_send', 'Zoom link and forms', 'When the link and forms are e-mailed to students who finished registration and payment.', 'top' ),
				$o( '#sdsc_class_status', 'Status', 'Upcoming, in progress, completed or cancelled — completed can be set by itself after the end date.', 'left' ),
				$o( '#publish', 'Save', '<b>Publish</b> / <b>Update</b> saves the class. Nothing changes on the website before you press it.', 'left' ),
			) ),
			'registrations' => array( 'registrations', 'Registrations', 'Every sign-up with the form, the signed contract and the signatures.', 'admin.php?page=sdsc-registrations', 'sdsc-registrations', array(
				$s( '', 'Registrations', 'Every sign-up from the website lands here with the student’s details, the class and the signed contract.', 'center' ),
				$o( '#sdsc-reg-search', 'Search', 'Find a registration by name, e-mail or phone.', 'bottom' ),
				$o( '#sdsc-reg-list', 'The list', 'Newest first: date, student, age, e-mail, phone, course code and where it came from.', 'top' ),
				$o( '#sdsc-reg-list tbody tr td:nth-child(2) a', 'Open one', 'Click the name to see the whole form, the contract and the student’s and parent’s signatures — ready to print.', 'right' ),
				$s( '', 'Privacy', 'This screen holds personal information. Do not share screenshots of it or export it outside the school.', 'center' ),
			) ),
			'reports' => array( 'reports', 'Student records (print)', 'Printable exam answer sheets and registration forms for the student file.', 'admin.php?page=sdsc-reports', 'sdsc-reports', array(
				$s( '', 'Student records', 'Complete records for the paper file: exam answer sheets and registration forms.', 'center' ),
				$s( '#sdsc-course-select', '1 · Course', 'Choose the online course the students took.', 'bottom' ),
				$s( '#sdsc-form-select', '2 · Registration form', 'Choose which registration form to match the students with.', 'bottom' ),
				$s( '.sdsc-reports-filter-form .button-primary', 'Get the data', 'Shows the students with their records; print the ones you need.', 'bottom' ),
				$s( '', 'Highly sensitive', 'Records can include dates of birth, addresses and signatures. Print only what you need, keep paper copies locked, never e-mail or photograph them, and close this screen when you step away.', 'center' ),
			) ),

			/* ---------------------------------------------------------------- instructors & cars */
			'df_instructors' => array( 'driveflow', 'Instructors & payouts', 'Instructor profiles, onboarding, agreements, pay rates and payouts.', 'admin.php?page=driveflow-pro-instructors', 'driveflow-pro-instructors', array(
				$s( '', 'Instructors & payouts', 'Each instructor’s profile, credentials, hourly rate, how they are paid, hours taught and what is owed.', 'center' ),
				$s( '.df-header-actions .df-btn-primary', 'Add an instructor', 'Name, phone, e-mail and working hours. Then send the onboarding link.', 'bottom' ),
				$s( 'h3@@Instructor Portals', 'Two links for instructors', '<b>In-car app</b> — the page instructors use on their phone for every lesson. <b>Onboarding portal</b> — where a new instructor uploads ID and badge, payment details and signs the agreement. Copy or open each one.', 'bottom' ),
				$s( 'table.df-table', 'The team', 'Credentials and tag, hourly rate, payment method, hours and pay, status.', 'top' ),
				$o( '.df-view-agreement-btn', 'Signed agreement', 'Opens the signed employment agreement, ready to print.', 'right' ),
				$o( '.df-toggle-instructor-status-btn', 'Active or not', 'Click to switch an instructor off. Past lessons and evaluations stay on record.', 'left' ),
				$o( 'table.df-table button@@Pay', 'Pay and history', '<b>Pay</b> records a payout for the lessons taught (balance due is shown in red). <b>History</b> lists every payout. <b>Edit</b> changes rate, payment details or photo; <b>Link</b> makes a new onboarding link.', 'left' ),
			) ),
			'df_vehicles' => array( 'driveflow', 'Cars', 'Training cars, Maryland plates and status.', 'admin.php?page=driveflow-pro-vehicles', 'driveflow-pro-vehicles', array(
				$s( '', 'Cars', 'Every training car with its Maryland plate, model, colour and status.', 'center' ),
				$s( '.df-header-actions .df-btn-primary', 'Add a car', 'Plate, model, colour. A car set to <b>maintenance</b> or <b>inactive</b> is not offered for booking.', 'bottom' ),
				$s( '.df-live-plate-studio', 'Plate preview', 'How the plate shows on the calendar, the lobby TV and the in-car app.', 'bottom' ),
				$s( 'table.df-table', 'The fleet', 'Click <b>Edit</b> to change a car or its status.', 'top' ),
				$s( '', 'Fuel and incidents', 'After each lesson the instructor reports the fuel level and any incident in the in-car app. Low fuel and incidents reach you as alerts.', 'center' ),
			) ),

			/* ---------------------------------------------------------------- money */
			'df_reports' => array( 'driveflow', 'Lesson accounting', 'Lessons taught, hours, pass rate, instructor pay and car use, for any period.', 'admin.php?page=driveflow-pro-reports', 'driveflow-pro-reports', array(
				$s( '', 'Lesson accounting', 'The numbers behind the behind-the-wheel lessons: lessons, hours, results, instructor pay and car use.', 'center' ),
				$s( '.df-date-pills', 'Period', 'Today, yesterday, this week, this month, last month, this year or all time.', 'bottom' ),
				$s( '.df-kpi-grid', 'Key numbers', 'Sessions, hours behind the wheel, completion rate, pass rate, active students, instructors and cars.', 'bottom' ),
				$s( '.df-report-nav', 'Reports', '<b>Executive Overview</b>, <b>Instructor Payroll &amp; Archive</b> (pay owed and paid), <b>Daily Breakdown</b>, <b>Monthly Trends</b>, <b>By Instructor</b>, <b>By Student</b> and <b>Fleet Utilization</b>.', 'bottom' ),
				$s( '.df-filters-form', 'Exact dates', 'Set from and to, search a name or plate, then <b>Apply Filter</b>.', 'bottom' ),
				$s( '.df-header-actions', 'Export and print', '<b>Export Current Report (CSV)</b> for the accountant, or <b>Print Official Report</b> on the school letterhead.', 'bottom' ),
			) ),
			'refunds' => array( 'refunds', 'Refunds', 'Full or prorated refunds by the written refund policy.', 'admin.php?page=sdsc-refunds', 'sdsc-refunds', array(
				$s( '', 'Refunds', 'The student asks in writing; the Zoom classes and behind-the-wheel sessions already used are kept and the rest goes back — exactly as the refund policy says.', 'center' ),
				$s( '.sdo-tabs', 'Three sections', '<b>Refunds made</b> — every refund with its receipt. <b>New refund</b> — the calculator. <b>Policy amounts</b> — what is kept per class and per drive (change only together with the written policy).', 'bottom' ),
				$s( '', 'Making a refund', 'New refund → find the student → their payments are found → the calculator shows what is kept and what goes back → <b>Review</b> → <b>Confirm</b>. Card payments go back to the card; cash or Zelle is recorded as manual. The student gets a receipt e-mail.', 'center' ),
				$s( '', 'Only once', 'A refund cannot be sent twice by mistake, and only managers can open this screen.', 'center' ),
			) ),
			'prices' => array( 'prices', 'Prices', 'Class and service prices — exactly what checkout charges.', 'admin.php?page=sdsc-prices', 'sdsc-prices', array(
				$s( '', 'Prices', 'The prices here are the ones checkout charges. The website never shows a price checkout does not use.', 'center' ),
				$s( 'h2@@Scheduled classes', 'Classes', 'Each scheduled class with its price now. Type a new price; if the class is paid through a Stripe payment link, paste the new link too.', 'bottom' ),
				$s( 'h2@@Courses, packages', 'Courses, packages and services', 'Behind-the-wheel packages, road-test car, online courses and more.', 'bottom' ),
				$s( '', 'Checkout already charges this price', 'Tick it only after you changed the price in Stripe as well. Until then the website keeps the old price, so a customer never sees one price and pays another.', 'center' ),
				$s( '#submit', 'Save', 'Nothing changes until you press <b>Save prices</b>.', 'top' ),
				$o( 'h2@@Price history', 'Price history', 'Every change, who made it and when.', 'top' ),
			) ),
			'discounts' => array( 'discounts', 'Site-wide sale', 'A percentage off a service for everyone, with a safe preview.', 'admin.php?page=sdsc-discounts', 'sdsc-discounts', array(
				$s( '', 'Site-wide sale', 'A percentage off a service for every visitor — the website then shows the old price crossed out and the new one.', 'center' ),
				$s( '.nav-tab-wrapper', 'All discount tools', 'This is one tab of <b>Discounts &amp; offers</b>: codes, early bird, site-wide sale, results and the guide.', 'bottom' ),
				$s( 'table.widefat', 'One row per service', 'Percent off, the new Stripe payment link (when the service is paid by link), when the offer ends, and <b>Checkout charges this price</b> — tick it only when the discount is really charged.', 'top' ),
				$o( 'a.button@@Preview', 'Preview first', 'Shows the page with the discount only to you, so you can check it before customers see it.', 'left' ),
				$s( '#submit', 'Save', '<b>Save discounts</b> publishes them.', 'top' ),
			) ),
			'promo' => array( 'promo', 'Discounts & offers', 'Discount codes, early-bird price, referral codes and what each brought in.', 'admin.php?page=sdsc-promo', 'sdsc-promo', array(
				$s( '', 'Discounts & offers', 'Codes students type at registration, the early-bird price and referral codes. The website checks every code, Stripe charges the discounted price and the receipt shows it. Discounts never add up — the student gets the better one.', 'center' ),
				$s( '.nav-tab-wrapper', 'Tabs', '<b>Discount codes</b> · <b>Early bird</b> (automatic lower price until some days before the start) · <b>Site-wide sale</b> · <b>Results</b> (paid uses and revenue per code and per source) · <b>How to use discounts</b>.', 'bottom' ),
				$o( '#sdsc-promo-list', 'Your codes', 'Each code with its discount, where it is used, classes, dates, paid uses and revenue. <b>Copy link</b> gives a registration link that fills the code in. <b>Turn off</b> stops a code at once.', 'top' ),
				$o( '#sdsc-form', 'New code', 'Letters and numbers, $ or % off, where it will be advertised (so Results can compare), which classes, from–to dates, how many uses and a note for staff.', 'top' ),
				$o( '#sdsc-promo-ref', 'Referral code', 'A code for one happy student to share: their friends get the discount you choose, up to a number of friends. You see how many registered.', 'left' ),
				$o( '#sdsc-promo-now', 'What each class shows now', 'The price each open class shows today after early bird or sale — so you always know what students see.', 'top' ),
				$s( '', 'Course codes', 'Each class keeps its real course number (set on the class, with the next number one tick away). A class without a code shows a reminder at the top of this screen.', 'center' ),
			) ),

			/* ---------------------------------------------------------------- messages */
			'texts' => array( 'texts', 'Texts to send', 'The school iPhone’s to-do list: every text that is due, one tap each.', 'admin.php?page=sdsc-texts', 'sdsc-texts', array(
				$s( '', 'Texts to send', 'Open this screen on the school iPhone in Safari and choose <b>Share → Add to Home Screen</b> — it then works like an app. Texts go out from the phone itself.', 'center' ),
				$s( '.sdo-title .sdo-n', 'How many are due', 'The counter shows the texts still to send.', 'right' ),
				$o( '.sdq-next', 'Text the next one', 'Opens Messages with the number and the message already written. Press Send, come back, tap again.', 'bottom' ),
				$o( '.sdq-card', 'One text', 'Who, why it is due and the message. <b>Text</b> marks it done after Messages opens, <b>Skip</b> hides it, <b>Undo</b> brings it back.', 'bottom' ),
				$o( '.sdo-lang', 'English or Spanish', 'Switch the language of this text before sending.', 'left' ),
				$o( '.sdq-done-all', 'All done', 'When nothing is due the list says so. New texts appear by themselves.', 'bottom' ),
				$s( '', 'What shows up here', 'Lesson confirmations · reminders before the free-cancel deadline · today’s lessons · cancellations · fees and waivers · Zoom class tomorrow and today · birthdays and licence anniversaries. Never advertising.', 'center' ),
			) ),
			'sms' => array( 'sms', 'Text messages log', 'Automatic texts through Twilio, a test text and the delivery log.', 'admin.php?page=sdsc-sms', 'sdsc-sms', array(
				$s( '', 'Text messages', 'When Twilio is connected, confirmations and reminders can go out as texts by themselves. Until then, use <b>Texts to send</b> on the school phone.', 'center' ),
				$o( '.sdo-hold', 'Connection', 'Shows whether Twilio is connected. The administrator enters the account details on API connections.', 'bottom' ),
				$s( 'form.sdo-settings@@Automatic texts', 'Automatic texts', 'Switch automatic texting on or off, and choose whether lesson confirmations, reminders and fee texts are included. Students can reply STOP at any time.', 'right' ),
				$s( 'form.sdo-settings@@Send a test', 'Test', 'Send a test text to your own phone.', 'left' ),
				$s( 'h2@@Last 100 texts', 'The log', 'The last 100 texts with their delivery status.', 'top' ),
			) ),
			'outreach' => array( 'outreach', 'Celebrations & campaigns', 'Birthday and licence-anniversary greetings, and group messages.', 'admin.php?page=sdsc-outreach', 'sdsc-outreach', array(
				$s( '', 'Celebrations & campaigns', 'Greet students on their birthday and on the anniversary of their licence, and send one message to many students — in English or Spanish.', 'center' ),
				$s( '.sdo-tabs', 'Five sections', '<b>Today</b> — who to greet today. <b>Students</b> — language and licence date per student. <b>Campaigns</b> — group e-mails and texts. <b>Message wording</b> — the English and Spanish texts. <b>Settings</b>.', 'bottom' ),
				$o( '#sdo-birthday', 'Birthdays today', 'The e-mail goes out by itself in the morning. On your phone, <b>Text</b> opens Messages with the greeting written — you only press Send.', 'bottom' ),
				$o( '#sdo-anniversary', 'Licence anniversaries', 'Appear once a student’s licence date is filled in on the Students tab — or tap <b>Ask</b> and they fill it in from a link.', 'bottom' ),
				$o( '.sdo-sec@@Coming up this week', 'Coming up', 'Who has a birthday or anniversary in the next days.', 'top' ),
				$o( '.sdo-lang', 'English or Spanish', 'Switch the language of a message before you send it.', 'left' ),
			) ),

			/* ---------------------------------------------------------------- website */
			'tv' => array( 'tv', 'Lobby TV', 'What the TV in the office shows: lessons, a message, the ticker and the view.', 'admin.php?page=sdsc-tv', 'sdsc-tv', array(
				$s( '', 'Lobby TV', 'The TV in the office shows today’s driving lessons — who, with which instructor and car, and the state of each lesson (awaiting start, ready, on the road, finished, cancelled) — plus your message and ticker.', 'center' ),
				$s( '#sdsc-tv-url', 'The TV address', 'Open this address once on the lobby TV in full screen. It updates by itself.', 'bottom' ),
				$s( '#sdsc-tv-preview', 'Live preview', 'Exactly what the TV shows right now. <b>Reload all lobby TVs</b> after a change to the TV page or if a screen looks stuck.', 'right' ),
				$s( '#sdsc-tv-msg', 'Message on the TV', 'A banner for everyone in the lobby — for example a delay. Choose the colour (information, notice, urgent) and press <b>Show on the TV</b>.', 'left' ),
				$s( '#sdsc-tv-ticker', 'Running ticker', 'One line each; the lines scroll at the bottom of the screen.', 'left' ),
				$s( '#sdsc-tv-display', 'Display', 'What the TV shows (lessons, cars, instructors, or rotate between them), how student names appear (first name and initial keeps privacy), and whether finished lessons stay on screen.', 'left' ),
			) ),
			'notices' => array( 'notices', 'Banners & alerts', 'Website banners, holiday templates, pop-ups and Montgomery County weather alerts.', 'admin.php?page=sdsc-notices', 'sdsc-notices', array(
				$s( '', 'Banners & alerts', 'Tell visitors about closures, holidays and news — in English, or English and Spanish.', 'center' ),
				$s( '.nav-tab-wrapper', 'Three tabs', '<b>Banners</b> you write · the <b>Holiday calendar</b> (US and Maryland days, set up once) · <b>Montgomery County alerts</b> from the National Weather Service.', 'bottom' ),
				$o( 'table.widefat', 'Your banners', 'What is live now and what is planned, with dates. <b>Edit</b> or <b>Remove</b>.', 'bottom' ),
				$o( '.sdnb-tpls', 'Start from a template', 'Each upcoming holiday and the closure templates fill in the text, design and dates for you. Change anything before saving.', 'top' ),
				$o( '#sdnb-form', 'Write a banner', 'Headline, message, optional button, language, design, where it shows and from–to dates. Never announce a price or discount unless checkout already charges it.', 'top' ),
				$o( '#sdnb-prev', 'Live preview', 'Exactly how the bar will look on the site.', 'auto' ),
				$s( '', 'County alerts need your OK', 'Weather alerts are fetched every 15 minutes but only appear on the website after someone presses <b>Show on website</b>. They hide themselves when they expire.', 'center' ),
			) ),
			'feed' => array( 'feed', 'Website listing', 'Which classes the website lists, and the style of the class signs.', 'admin.php?page=sdsc-feed', 'sdsc-feed', array(
				$s( '', 'Website listing', 'The homepage and Services page list the classes you add in Classes — dates, days and time, price and seats left — and each Enroll button opens the registration form with that class chosen.', 'center' ),
				$s( '.form-table', 'Settings', 'List classes from Class Manager on or off, which page registers, when to show “only N seats left”, and how many classes to show. Press <b>Save</b>.', 'right' ),
				$s( '#sdsc-sign', 'Class sign style', 'Every class is drawn as a road sign. Pick a style; the previews use your next class. Then <b>Use the selected sign</b>.', 'bottom' ),
				$o( 'table.widefat', 'What the site will show', 'The classes as visitors will see them, with seats left and the enroll link. A class leaves the list by itself when it starts, is full or is cancelled.', 'top' ),
			) ),
			'mva' => array( 'mva', 'MVA headlines', 'Today’s MVA news and how to turn one into an article.', 'admin.php?page=sdsc-mva', 'sdsc-mva', array(
				$s( '', 'MVA headlines', 'Headlines from the Maryland MVA, checked once a day. The website shows only the headline, date and a link — the MVA text belongs to the State.', 'center' ),
				$s( '#sdsc-mva-check', 'Check now', 'Fetches the latest headlines right away.', 'bottom' ),
				$s( 'table.widefat', 'Headlines', 'Each headline links to the MVA page. <b>Shown on site</b> says whether it is listed on the website.', 'top' ),
				$o( 'table.widefat .button', 'Write a post', 'Starts a draft article. Explain the change in your own words for learners and parents — never copy the MVA text.', 'left' ),
			) ),

			/* ---------------------------------------------------------------- setup & help */
			'blocklist' => array( 'blocklist', 'Blocked sign-ups', 'Stop spam accounts and see who was refused.', 'admin.php?page=sdsc-blocklist', 'sdsc-blocklist', array(
				$s( '', 'Blocked sign-ups', 'Addresses and domains listed here cannot create an account on any form of the website. Anyone refused is asked to call the school.', 'center' ),
				$s( '#sdsc-bl-e', 'Blocked e-mails', 'One address per line. Gmail look-alikes (extra dots, “+” tags) are caught too.', 'right' ),
				$s( '#sdsc-bl-d', 'Blocked domains', 'Every address at these domains is refused. Never add gmail.com, yahoo.com or similar — real students use them.', 'right' ),
				$s( '#submit', 'Save', '<b>Save list</b> applies it at once.', 'top' ),
				$o( 'table.widefat', 'Refused attempts', 'Every refused sign-up, including robots caught by the hidden trap. Real visitors never see the trap.', 'top' ),
			) ),
			'updates' => array( 'updates', 'What’s new', 'Explanations of every change to the website and your tools.', 'admin.php?page=sdsc-updates', 'sdsc-updates', array(
				$s( '', 'What’s new', 'Every time something changes on the website or in your tools, the explanation appears here.', 'center' ),
				$o( '#sdsc-upd-list', 'Updates', 'Newest first. Items you have not read are marked <b>New</b>, and the menu shows a counter until you open this screen.', 'top' ),
				$o( '#sdsc-upd-form', 'Write an update', 'Administrators: write what changed, choose who it is for and, if you like, e-mail them a copy.', 'bottom' ),
			) ),
			'api' => array( 'api', 'API connections', 'Where Stripe, Zoom and Twilio keys are stored.', 'admin.php?page=sdsc-api', 'sdsc-api', array(
				$s( '', 'API connections', 'Keys that connect the site to Stripe, Zoom and Twilio. They are stored encrypted.', 'center' ),
				$o( '.wrap .form-table', 'Keys', 'Paste keys here yourself. Never send a key by e-mail or chat.', 'right' ),
				$o( '.wrap .wp-list-table, .wrap table.widefat', 'Connection test', 'Use Test to check a key works before relying on it.', 'top' ),
			) ),
			'settings' => array( 'settings', 'Settings', 'General Class Manager settings.', 'admin.php?page=sdsc-settings', 'sdsc-settings', array(
				$s( '', 'Settings', 'General options. Change them only when you know what they do.', 'center' ),
				$o( '.wrap .form-table', 'Zoom account names', 'The names shown on the class form when you pick a Zoom account.', 'right' ),
			) ),
			'email' => array( 'email', 'E-mail', 'How school e-mails are sent and checked.', 'admin.php?page=sdsc-email', 'sdsc-email', array(
				$s( '', 'E-mail', 'Class and order e-mails come from info@, passwords and notices from noreply@. Every e-mail gets the school layout and signature.', 'center' ),
				$o( '#sdsc-mail-checks', 'Delivery checklist', 'All green means Gmail and Outlook trust the school’s e-mails.', 'bottom' ),
				$o( '#sdsc-mail-boxes', 'Mailboxes', 'Type each mailbox password once. It is stored encrypted and never shown again.', 'top' ),
				$o( '.sdsc-mail-test', 'Test', 'Send yourself a test after every change.', 'top' ),
			) ),
			'team' => array( 'team', 'Team access', 'Decide what instructors and front-desk staff can open.', 'admin.php?page=sdsc-team', 'sdsc-team', array(
				$s( '', 'Team access', 'Give each role exactly the screens it needs — nothing more.', 'center' ),
				$o( '.sdsc-team-roles', 'Roles', 'How many people have each role. <b>View</b> lists them.', 'bottom' ),
				$o( '#sdsc-team-matrix', 'Who can open what', 'Switch a screen on or off for instructors or front desk. People only see the screens — and tours — they are allowed.', 'top' ),
				$o( '.sdsc-team-lock', 'Administrator only', 'Keys, settings and this screen always stay with administrators.', 'left' ),
				$o( '.wrap #submit', 'Save', 'Changes apply on the person’s next page load.', 'top' ),
			) ),
			'blog' => array( 'edit_posts', 'Driving Tips articles', 'Write an article and file it under the right topic.', 'edit.php', 'edit-post', array(
				$s( '', 'Driving Tips & News', 'Articles here appear on the website’s Driving Tips page, grouped by topic.', 'center' ),
				$o( '.wrap .page-title-action', 'New article', 'Start a new article here.', 'bottom' ),
				$o( '#cat', 'Topics', 'Filter the list by topic: Permit &amp; License, Road Test &amp; Skills, Maryland Laws, Safe Driving and more.', 'bottom' ),
				$s( '', 'In the editor', 'Pick one topic under <b>Categories</b>, then use <b>Article details</b> to set the level, the language and whether it is the featured article. Reading time is calculated for you.', 'center' ),
			) ),
		);
	}

	/* ------------------------------------------------------------ website (front-end) tours */

	/** key => [title, summary, steps]. Shown on the student and instructor pages of the website. */
	public static function front_tours() {
		$s = function ( $target, $title, $content, $place = 'auto', $opt = false ) {
			return array( 'target' => $target, 'title' => $title, 'content' => $content, 'placement' => $place, 'skip' => $opt ? 1 : 0 );
		};
		$o = function ( $target, $title, $content, $place = 'auto' ) use ( $s ) { return $s( $target, $title, $content, $place, true ); };
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
			'instructor_app' => array( 'In-car lesson app', 'What instructors do on their phone before, during and after every lesson.', array(
				$s( '', 'Your in-car lesson app', 'Use this page on your phone or tablet for every behind-the-wheel lesson. It updates by itself when the office books, moves or cancels a lesson.', 'center' ),
				$o( '.sca-sync', 'Live', 'The green dot means you see the latest schedule. Changes from the office also arrive as alerts on the bell.', 'bottom' ),
				$o( '#sdsc-dock-mount', 'Alerts and messages', 'The bell shows lessons booked, moved, reassigned or cancelled for you. Messages reach the office (and your students, if the school turned that on).', 'bottom' ),
				$o( '.sca-pick', 'Instructor', 'The office can switch between instructors here.', 'bottom' ),
				$o( '.sca-next', 'Next up', 'Your next lesson today. Tap it to open the lesson.', 'bottom' ),
				$o( '.sca-seg', 'Today · Needs report · Upcoming', '<b>Today</b> — today’s lessons. <b>Needs report</b> — lessons that ended without an evaluation (red count: do these first). <b>Upcoming</b> — what is booked next.', 'bottom' ),
				$o( '.sca-list', 'A lesson card', 'Time, student, lesson number, car and status. Tap a card to open the student: every past lesson with any instructor, the skills still to practise and the last note.', 'top' ),
				$o( '.sca-walk', 'Not on the schedule?', 'Start a lesson that the office has not booked yet. Tell the office afterwards.', 'top' ),
				$o( '.sca-steps', 'The report in three parts', '<b>1 Skills</b>, <b>2 Car check</b>, <b>3 Notes &amp; signatures</b>. Each part shows when it is complete.', 'bottom' ),
				$o( '#sca-s1', '1 · Skills', 'Rate each skill you practised (nothing is pre-filled). <b>Rate the rest “4”</b> speeds things up. Earlier ratings from other instructors are shown under each skill.', 'top' ),
				$o( '#sca-s2', '2 · Car check', 'Plate, fuel level (below a quarter: refuel with the student and enter gallons and cost) and any incident. An incident or low fuel alerts the office at once.', 'top' ),
				$o( '#sca-s3', '3 · Notes & signatures', 'Advice for the next lesson, the result, then your signature and the student’s with a finger.', 'top' ),
				$o( '#sca-foot', 'Save', 'Your answers stay on this phone until you save, even without signal. Saving closes the lesson, sends the student their scorecard and updates the office.', 'top' ),
				$s( '', 'Before you drive', 'Ask to see the valid learner’s permit. No permit — no lesson: tell the office. Running late, sick or a car problem? Call the office; never cancel a lesson yourself.', 'center' ),
			) ),
			'workspace' => array( 'Your workspace (staff)', 'The Workspace menu, quick actions, the sidebar, alerts and messages.', array(
				$s( '', 'Your workspace', 'Everything for running the school opens here, inside the dashboard. You only see what your role may open.', 'center' ),
				$o( 'top:.sws-menu', 'Workspace menu', 'Every screen, in groups: Lessons &amp; attendance, Students &amp; classes, Instructors &amp; cars, Money &amp; accounting, Messages, Reports, Website, Setup and Help.', 'bottom' ),
				$o( 'top:.sws-quick', 'Quick actions', 'Book a lesson, add a class, add a student, Zoom attendance and reports — one click each.', 'bottom' ),
				$o( 'top:.sws-home', 'All screens as cards', 'The same screens with a one-line description each.', 'top' ),
				$o( 'top:.tutor-dashboard-left-menu, .tutor-dashboard-menu', 'Sidebar', 'Your everyday screens.', 'right' ),
				$o( 'top:.sdk-btn--bell', 'Alerts', 'New registrations and payments, lessons booked, moved or cancelled, evaluations with incidents or low fuel.', 'left' ),
				$o( 'top:.sdk-btn--chat', 'Messages', 'Conversations with students and instructors.', 'left' ),
				$s( '', 'Help on every screen', 'Each screen has a <b>How this screen works</b> button next to its title. All tours are in <b>Help → Guided tours</b>.', 'center' ),
			) ),
			'zoom_attendance' => array( 'Zoom attendance', 'Take attendance for a Zoom class session, right after class.', array(
				$s( '', 'Zoom attendance', 'Right after each Zoom class, mark who was there. The office, the student’s record and the absence count update at once.', 'center' ),
				$o( '.szr-todo', 'Still to submit', 'Sessions that have happened but have no sheet yet. Open one to fill it in.', 'bottom' ),
				$o( '.szr-pick', 'Class', 'Choose the class (name and start date).', 'bottom' ),
				$o( '.szr-tabs', 'Sessions', 'One button per session with its date. Session 1 is mandatory (MVA).', 'bottom' ),
				$o( '.szr-card .szr-head', 'This session', 'Date and time, and <b>Open Zoom</b>. The checklist opens on the day of the class.', 'bottom' ),
				$o( '.szr-tools', 'Mark everyone present', 'Then change only the few who were late, left early or absent.', 'bottom' ),
				$o( '.szr-seg', 'One student', 'Present · Late · Left early · Absent. ✓ / ✗ next to the name is what the student answered to the reminder.', 'left' ),
				$o( '.szr-note', 'Note', 'Optional: camera off, joined late…', 'top' ),
				$o( 'button.szr-btn@@Submit attendance', 'Submit', 'Saves the sheet. You can still correct it later.', 'top' ),
				$o( '.szr-grid', 'All sessions', 'The whole class at a glance: ✓ present · L late · E left early · ✗ absent · ? not marked, and the total attended.', 'top' ),
			) ),
			'reports_print' => array( 'Reports & printing', 'Rosters, attendance, registrations, payments, lessons and fees — print or download.', array(
				$s( '', 'Reports & printing', 'Ready-made reports for the office and the file. Each one prints on the school letterhead or downloads as CSV.', 'center' ),
				$o( '.srp-cards', 'Pick a report', '<b>Class roster</b>, <b>Class attendance</b>, <b>Registrations</b>, <b>Online payments</b>, <b>Driving lessons</b> and <b>Lesson fees</b>. You see the ones your role may open.', 'bottom' ),
				$o( '.srp-filters', 'Choose', 'A class, or a date range — and for lessons and fees the instructor and status. Then <b>Show</b>.', 'bottom' ),
				$o( '.srp-actions', 'Print or download', '<b>Print / Save PDF</b> opens the letterhead version; <b>Download CSV</b> for a spreadsheet.', 'bottom' ),
				$o( '.srp-totals', 'Totals', 'The sums for the report.', 'bottom' ),
				$o( '.srp-scroll', 'The report', 'The first 300 rows on screen; print or download for all.', 'top' ),
				$o( '.srp-access', 'Who can open reports', 'The administrator and the school manager choose which reports front desk and instructors may open. Instructors only ever see their own classes and lessons.', 'top' ),
			) ),
			'messages' => array( 'Messages', 'Conversations with the school, the office and instructors.', array(
				$s( '', 'Messages', 'Every conversation in one place. New messages also show on the chat button at the bottom right; an e-mail follows if a message stays unread for ten minutes.', 'center' ),
				$o( '.sdk-find', 'Find someone', 'Office: type a student’s or instructor’s name to open a conversation.', 'bottom' ),
				$o( '.sdk-tog', 'Student ↔ instructor messages', 'Off by default. When the school manager turns it on, students can message their own instructor about lessons they have together.', 'bottom' ),
				$o( '.sdk-threads', 'Conversations', 'Newest first; unread ones are marked. Click one to open it.', 'right' ),
				$o( '.sdk-compose', 'Write', 'Type and press Send.', 'top' ),
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


	/** Dashboard tab => website tour key (staff pages of the school dashboard). */
	protected static function tab_tours() {
		return array( 'sdsc-zoom-attendance' => 'zoom_attendance', 'sdsc-reports' => 'reports_print', 'sdsc-messages' => 'messages' );
	}

	/** Tour of a workspace screen (the screen is shown in a frame; the tour runs on the dashboard around it). */
	public static function screen_tour( $screen ) {
		if ( ! class_exists( 'SDSC_Workspace' ) ) { return ''; }
		if ( 'tours' === $screen ) { return isset( self::mine()['start'] ) ? 'start' : ''; }
		$sc = SDSC_Workspace::screens()[ $screen ] ?? null;
		if ( ! $sc || empty( $sc[3] ) ) { return ''; }
		foreach ( self::mine() as $k => $t ) {
			if ( in_array( $k, array( 'start', 'path_manager', 'path_frontdesk' ), true ) ) { continue; }
			if ( $t[3] === $sc[2] ) { return $k; }
		}
		return '';
	}

	/** Which tour belongs on the website page being shown: [ key, steps, title, runs-on-a-screen-frame ]. */
	public static function front_key() {
		$df  = (array) get_option( 'driveflow_pro_settings', array() );
		$map = array( 'magic_booking_page_id' => 'student_booking', 'student_portal_page_id' => 'student_portal', 'instructor_page_id' => 'instructor_app', 'instructor_onboarding_page_id' => 'instructor_onboarding' );
		foreach ( $map as $opt => $k ) {
			if ( ! empty( $df[ $opt ] ) && is_page( (int) $df[ $opt ] ) ) { return $k; }
		}
		if ( ! is_user_logged_in() ) { return ''; }
		$tab = (string) get_query_var( 'tutor_dashboard_page' );
		if ( class_exists( 'SDSC_Workspace' ) && SDSC_Workspace::is_staff() ) {
			if ( SDSC_Workspace::TAB === $tab ) {
				$screen = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				return $screen ? ( self::screen_tour( $screen ) ? 'ws:' . self::screen_tour( $screen ) : '' ) : 'workspace';
			}
			if ( isset( self::tab_tours()[ $tab ] ) ) { return self::tab_tours()[ $tab ]; }
			$dash = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) : 0;
			if ( $dash && is_page( $dash ) && ( '' === $tab || 'index' === $tab ) ) { return 'workspace'; }
		} elseif ( 'sdsc-messages' === $tab ) {
			return 'messages';
		}
		if ( class_exists( 'SDSC_Lessons' ) && SDSC_Lessons::TAB === $tab ) { return 'student_lessons'; }
		$p = get_post();
		if ( is_singular() && $p && has_shortcode( (string) $p->post_content, 'sdsc_my_lessons' ) ) { return 'student_lessons'; }
		return '';
	}

	/** Website page of each tour, for the list of tours. */
	public static function front_url( $k ) {
		if ( 'workspace' === $k ) { return class_exists( 'SDSC_Workspace' ) ? SDSC_Workspace::url( '' ) : ''; }
		if ( 'zoom_attendance' === $k ) { return class_exists( 'SDSC_ZoomRoll' ) && SDSC_ZoomRoll::allowed() ? SDSC_ZoomRoll::url() : ''; }
		if ( 'reports_print' === $k ) { return class_exists( 'SDSC_Reports' ) && SDSC_Reports::mine() ? SDSC_Reports::url() : ''; }
		if ( 'messages' === $k ) { return function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( 'sdsc-messages' ) : ''; }
		if ( 'student_lessons' === $k ) { return class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::my_url() : ''; }
		$df  = (array) get_option( 'driveflow_pro_settings', array() );
		$ids = array( 'student_booking' => 'magic_booking_page_id', 'student_portal' => 'student_portal_page_id', 'instructor_app' => 'instructor_page_id', 'instructor_onboarding' => 'instructor_onboarding_page_id' );
		return ! empty( $df[ $ids[ $k ] ?? '' ] ) ? get_permalink( (int) $df[ $ids[ $k ] ] ) : '';
	}

	/** Where a staff tour opens: the workspace screen for everyone but the administrator in wp-admin. */
	public static function tour_url( $k ) {
		$t = self::tours()[ $k ] ?? null;
		if ( ! $t ) { return ''; }
		$in_ws = class_exists( 'SDSC_Workspace' ) && ( ! current_user_can( 'manage_options' ) || ( class_exists( 'SDSC_Private' ) && SDSC_Private::embedded() ) );
		if ( $in_ws ) {
			foreach ( SDSC_Workspace::screens() as $sk => $sc ) {
				if ( ! empty( $sc[3] ) && ( $sc[2] === $t[3] ) ) { return add_query_arg( 'tour', $k, SDSC_Workspace::url( $sk ) ); }
			}
		}
		return add_query_arg( 'sdsc_tour', $k, admin_url( $t[3] ) );
	}

	/** Data for the tour engine. */
	protected static function data( $key, $title, $steps, $frame, $auto ) {
		return array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sdsc_tour' ),
			'key'   => $key,
			'auto'  => $auto ? 1 : 0,
			'seen'  => is_user_logged_in() && in_array( $key, self::seen(), true ) ? 1 : 0,
			'title' => $title,
			'steps' => array_values( $steps ),
			'frame' => $frame ? 1 : 0,
			'list'  => '',
		);
	}

	/** [ key, title, steps, frame ] for the page being shown, or null. */
	protected static function front_tour() {
		$key = self::front_key();
		if ( ! $key ) { return null; }
		if ( 0 === strpos( $key, 'ws:' ) ) {
			$k = substr( $key, 3 );
			return array( $k, self::tours()[ $k ][1], self::steps( $k ), true );
		}
		$t = self::front_tours()[ $key ] ?? null;
		return $t ? array( $key, $t[0], $t[2], false ) : null;
	}

	public static function front_assets() {
		$t = self::front_tour();
		if ( ! $t ) { return; }
		$want = '';
		foreach ( array( 'tour', 'sdsc_tour' ) as $q ) { if ( isset( $_GET[ $q ] ) ) { $want = sanitize_key( wp_unslash( $_GET[ $q ] ) ); } } // phpcs:ignore WordPress.Security.NonceVerification
		wp_enqueue_style( 'sdsc-admin-ui', SDSC_URL . 'assets/admin-ui.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-admin-ui', SDSC_URL . 'assets/admin-ui.js', array(), SDSC_VERSION, true );
		wp_localize_script( 'sdsc-admin-ui', 'SDSC_TOUR', self::data( $t[0], $t[1], $t[2], $t[3], $want === $t[0] ) );
	}

	/** For pages that draw their own document (the in-car app): the tour files as tags. */
	public static function tags( $key ) {
		$t = self::front_tours()[ $key ] ?? null;
		if ( ! $t || wp_script_is( 'sdsc-admin-ui', 'enqueued' ) ) { return ''; }
		$want = isset( $_GET['sdsc_tour'] ) ? sanitize_key( wp_unslash( $_GET['sdsc_tour'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return '<link rel="stylesheet" href="' . esc_url( SDSC_URL . 'assets/admin-ui.css?ver=' . SDSC_VERSION ) . '">'
			. '<script>window.SDSC_TOUR=' . wp_json_encode( self::data( $key, $t[0], $t[2], false, $want === $key ) ) . ';</script>'
			. '<script src="' . esc_url( SDSC_URL . 'assets/admin-ui.js?ver=' . SDSC_VERSION ) . '" defer></script>';
	}

	/** Tours this person may take. */
	public static function mine() {
		if ( ! class_exists( 'SDSC_Access' ) || ! SDSC_Access::is_staff() ) { return array(); }
		$out = array();
		foreach ( self::tours() as $k => $t ) {
			$ok = isset( SDSC_Access::features()[ $t[0] ] ) ? SDSC_Access::can( $t[0] ) : current_user_can( $t[0] );
			if ( $ok ) { $out[ $k ] = $t; }
		}
		return $out;
	}

	/** Tour for the wp-admin screen being shown, if any. */
	protected static function current_key() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$want   = isset( $_GET['sdsc_tour'] ) ? sanitize_key( wp_unslash( $_GET['sdsc_tour'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$mine   = self::mine();
		$match  = function ( $t ) use ( $page, $screen ) {
			if ( $page ) { return $page === $t[4]; }
			if ( ! $screen ) { return false; }
			return $screen->id === $t[4] || ( 'edit-sdsc_class' === $t[4] && 'sdsc_class' === $screen->id );
		};
		if ( $want && isset( $mine[ $want ] ) && $match( $mine[ $want ] ) ) { return $want; }
		foreach ( $mine as $k => $t ) {
			if ( $match( $t ) ) { return $k; }
		}
		return '';
	}

	protected static function steps( $key ) {
		return self::tours()[ $key ][5];
	}

	protected static function seen() {
		$s = get_user_meta( get_current_user_id(), self::META, true );
		return is_array( $s ) ? $s : array();
	}

	/* ------------------------------------------------------------ admin */

	public static function assets() {
		if ( ! class_exists( 'SDSC_Access' ) || ! SDSC_Access::is_staff() ) { return; }
		$key  = self::current_key();
		$auto = isset( $_GET['sdsc_tour'] ) ? sanitize_key( wp_unslash( $_GET['sdsc_tour'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$data = self::data( $key, $key ? self::tours()[ $key ][1] : '', $key ? self::steps( $key ) : array(), false, $auto && $auto === $key );
		$data['list'] = admin_url( 'admin.php?page=sdsc-tours' );
		wp_localize_script( 'sdsc-admin-ui', 'SDSC_TOUR', $data );
	}

	public static function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) { return; }
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
		$seen  = self::seen();
		$u     = wp_get_current_user();
		$r     = (array) $u->roles;
		$role  = current_user_can( 'manage_options' ) ? 'admin' : ( in_array( SDSC_Access::MANAGER, $r, true ) ? 'manager' : ( in_array( SDSC_Access::FRONTDESK, $r, true ) ? 'frontdesk' : 'instructor' ) );
		$top   = ! current_user_can( 'manage_options' ) || ( class_exists( 'SDSC_Private' ) && SDSC_Private::embedded() );
		$tgt   = $top ? ' target="_top"' : '';
		$paths = array( 'start', 'path_manager', 'path_frontdesk' );
		$card  = function ( $url, $title, $desc, $n, $done, $start, $extra = '' ) {
			return '<a class="sdsc-tour-card' . ( $start ? ' is-start' : '' ) . '" href="' . esc_url( $url ) . '"' . $extra . '><span class="sdsc-tour-card-t">' . esc_html( $title ) . '</span><span class="sdsc-tour-card-d">' . esc_html( $desc ) . '</span>'
				. '<span class="sdsc-tour-card-f"><span>' . (int) $n . ' steps</span>' . ( $done ? '<span class="sdsc-tour-done">&#10003; Seen</span>' : '<span class="sdsc-tour-new">New</span>' ) . '<b>Start &rarr;</b></span></a>';
		};
		echo '<div class="wrap sdsc-tours"><h1>Guided tours</h1>';
		echo '<p class="sdsc-lead">Hi ' . esc_html( $u->display_name ) . ' — you are signed in as <span class="sdsc-role-chip sdsc-role-chip--' . esc_attr( $role ) . '">' . esc_html( SDSC_Access::role_label( $role ) ) . '</span>. Pick a screen and we&rsquo;ll walk you through it. Every screen also has a <b>How this screen works</b> button next to its title.</p>';
		echo '<h2 class="sdsc-tours-h2">Start here</h2><div class="sdsc-tour-grid" id="sdsc-tour-list">';
		foreach ( self::mine() as $k => $t ) {
			if ( ! in_array( $k, $paths, true ) ) { continue; }
			$url = $top && class_exists( 'SDSC_Workspace' ) ? add_query_arg( 'tour', $k, SDSC_Workspace::url( 'tours' ) ) : add_query_arg( 'sdsc_tour', $k, admin_url( $t[3] ) );
			echo $card( $url, $t[1], $t[2], count( $t[5] ), in_array( $k, $seen, true ), 'start' === $k, $tgt ); // phpcs:ignore -- escaped in $card
		}
		$dash = array( 'workspace', 'zoom_attendance', 'reports_print', 'messages' );
		foreach ( $dash as $k ) {
			$url = self::front_url( $k );
			if ( ! $url ) { continue; }
			$t = self::front_tours()[ $k ];
			echo $card( add_query_arg( 'tour', $k, $url ), $t[0], $t[1], count( $t[2] ), in_array( $k, $seen, true ), false, $tgt ); // phpcs:ignore
		}
		echo '</div>';
		$groups = class_exists( 'SDSC_Workspace' ) ? SDSC_Workspace::groups() : array();
		$by     = array();
		foreach ( self::mine() as $k => $t ) {
			if ( in_array( $k, $paths, true ) ) { continue; }
			$g = 'setup';
			if ( class_exists( 'SDSC_Workspace' ) ) {
				foreach ( SDSC_Workspace::screens() as $sc ) { if ( $sc[2] === $t[3] ) { $g = $sc[1]; break; } }
			}
			if ( 'blog' === $k ) { $g = 'website'; }
			$by[ $g ][] = array( $k, $t );
		}
		foreach ( $groups as $g => $label ) {
			if ( empty( $by[ $g ] ) ) { continue; }
			echo '<h2 class="sdsc-tours-h2">' . esc_html( $label ) . '</h2><div class="sdsc-tour-grid">';
			foreach ( $by[ $g ] as $x ) {
				echo $card( self::tour_url( $x[0] ), $x[1][1], $x[1][2], count( $x[1][5] ), in_array( $x[0], $seen, true ), false, $tgt ); // phpcs:ignore
			}
			echo '</div>';
		}
		echo '<h2 class="sdsc-tours-h2">What students and instructors see</h2><p class="sdsc-lead">These tours run on the website pages of students and instructors (the <b>Take the tour</b> button). Open one to see exactly what they see.</p><div class="sdsc-tour-grid">';
		foreach ( array( 'student_lessons', 'student_booking', 'student_portal', 'instructor_app', 'instructor_onboarding' ) as $k ) {
			$url = self::front_url( $k );
			if ( ! $url ) { continue; }
			$t = self::front_tours()[ $k ];
			echo $card( add_query_arg( 'sdsc_tour', $k, $url ), $t[0], $t[1], count( $t[2] ), in_array( $k, $seen, true ), false, ' target="_blank" rel="noopener"' ); // phpcs:ignore
		}
		echo '</div></div>';
	}
}
