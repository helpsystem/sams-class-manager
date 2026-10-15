<?php
/**
 * The Parent & Student Responsibility Agreement — the school's official policy text for the Driver Education
 * program (classroom on Zoom and behind the wheel), word for word as it was printed on every student's
 * registration form. Printed in full with each registration form.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Agreement {

	const TITLE = 'Parent & Student Responsibility Agreement';

	public static function init() {}

	/** Sections: [ heading, [ [item title, text], … ], closing note ]. */
	public static function sections() {
		return array(
			array( 'Classroom (Virtual/Zoom) Policies', array(
				array( '1. First-Day Attendance (Mandatory).', 'All students must attend the first day of class in accordance with Maryland Motor Vehicle Administration (MVA) regulations. Failure to attend the first day will result in automatic cancellation of enrollment, with no continuation or make-up option.' ),
				array( '2. Virtual Classroom Standards (MVA Compliance).', 'Virtual instruction is conducted under the same standards of supervision, conduct, and participation as in-person classes. Students must: Attend live, instructor-led sessions in real time (no recordings or self-paced instruction). Maintain a working camera and microphone at all times. Remain visible on camera for the entire class session. Sit at a desk, table, or appropriate writing surface. Remain attentive and stationary at all times. Maintain professional and appropriate classroom behavior at all times.' ),
				array( '3. Student Conduct & Participation.', 'Students are expected to behave respectfully and responsibly at all times. Disruptive, inappropriate, or disrespectful behavior toward instructors or classmates may result in dismissal from Sam Driving School. Students must: Attend every scheduled class, Actively participate, Complete all quizzes and assignments, and Pass the final exam.' ),
				array( '4. Attendance Requirements.', 'Students may not continue in the program if they: Consistently arrive late, Leave early, and Miss more than four (4) classroom sessions. Attendance is monitored visually and electronically in compliance with MVA requirements.' ),
				array( '5. Curriculum & Monitoring.', 'Students must be able to view the MVA-approved PowerPoint curriculum, including all embedded videos. All quizzes and exams are completed through a secure system. Recording, photographing, copying, or downloading quizzes or tests is strictly prohibited.' ),
			), 'Sam Driving School allows the MVA to monitor or audit classes without notice, as required.' ),
			array( 'Behind-the-Wheel (BTW) Policies', array(
				array( '6. Learner’s Permit Requirement.', 'A valid Maryland learner’s permit is required for all behind-the-wheel lessons. Lessons will not be conducted without proper documentation.' ),
				array( '7. Late Cancellation / No-Show Fee.', 'A $60.00 fee will be charged for: Failure to appear for a scheduled lesson, Cancellation with less than 48 hours’ notice, Arriving without a valid learner’s permit.' ),
				array( '8. Meeting Location.', 'Students must meet the instructor at the school-designated location. Pick-up and drop-off services are not offered.' ),
				array( '9. Instructor Evaluation & Lesson Deferral.', 'If an instructor determines that a student is not prepared to progress: The lesson may be postponed, Additional practice with a parent/mentor may be required, Extra Practice sessions may be recommended. If a student fails the final BTW lesson, the student must: Practice independently, Re-schedule the failed lesson, Complete an additional two (2) hours of BTW instruction.' ),
				array( '10. Break Time.', 'Each two-hour BTW lesson includes a 10-minute instructional break, used at the instructor’s discretion to review student strengths and areas for improvement.' ),
				array( '11. Scheduling & Completion Timeline (COMAR Compliance).', 'The BTW program consists of three (3) two-hour sessions, each on a separate day. Lessons must be scheduled by phone or text message only (email scheduling is not accepted). Classroom and BTW instruction must be completed within 18 weeks, unless an extension is requested and approved per MVA regulations. Completion timelines may vary based on student availability and school scheduling.' ),
				array( '', 'In accordance with COMAR 11.23.02.08C(8), a certified driving school is required to complete both classroom and behind-the-wheel instruction within eighteen (18) weeks unless the student requests an extension. Sam Driving School is responsible for ensuring compliance with the 18-week requirement and will maintain documentation demonstrating that the student requested an extension or failed to respond to the school’s reasonable efforts to complete the program within the required timeframe. Such documentation will be provided to the Maryland Motor Vehicle Administration (MVA) upon request.' ),
				array( '12. Respect & Professional Conduct.', 'Students are entitled to a respectful learning environment and are expected to: Treat instructors courteously, Accept constructive feedback, and Follow all safety rules and instructional guidance.' ),
				array( '13. School Transparency.', 'Students and parents/driver coaches have the right to: Visit the school, Review instructor licenses and certifications upon request.' ),
				array( '14. MVA Reporting.', 'Upon successful completion of: 30 hours of classroom/virtual instruction, and 6 hours of behind-the-wheel instruction. Sam Driving School will electronically submit completion records to the Maryland Motor Vehicle Administration (MVA).' ),
			), '' ),
			array( 'Other Important Information', array(
				array( '15. Refund Policy.', 'Refund requests must be submitted in writing to: samdrivingschool.rockville@gmail.com. Refunds are prorated as follows: This is based on total price of $378(30% zoom classes and 70% behind the wheel). Each zoom class is $11.5 per completed classroom session, and each Behind-the-Wheel session is $88.' ),
				array( '16. Complaints.', 'If student or parent has any complaint or concern feel free to contact Sam driving school at 202-600-0889/ 301-726-3030. Each parent/driver coach and student has the right to place a complaint with MVA regarding problems associated with the driving school or the instructor at 410-424-3751. With that right each parent/driver coach and student have the responsibility to attempt to promptly pay the driving school for the driver education class and to attempt to alert the owner or manager of the driving school about any problems or complaints before contacting an outside agency.' ),
			), '' ),
		);
	}

	public static function html() {
		$h = '';
		foreach ( self::sections() as $sec ) {
			$h .= '<h4>' . esc_html( $sec[0] ) . '</h4>';
			foreach ( $sec[1] as $it ) {
				$h .= '<p>' . ( $it[0] ? '<b>' . esc_html( $it[0] ) . '</b> ' : '<i>' ) . esc_html( $it[1] ) . ( $it[0] ? '' : '</i>' ) . '</p>';
			}
			if ( $sec[2] ) { $h .= '<p><i>' . esc_html( $sec[2] ) . '</i></p>'; }
		}
		return $h;
	}
}
