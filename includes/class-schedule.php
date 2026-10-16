<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Class calendar, "I'll be there" confirmations and Zoom attendance rules.
 *
 * Calendar
 *  - Each class gets a real timetable (weekdays, start and end time, number of sessions). The session
 *    dates are worked out from the class start date, skipping days marked "School closed" on the
 *    holiday calendar.
 *  - Every student has one private calendar address with all their Zoom sessions and behind-the-wheel
 *    lessons. Apple Calendar, Google Calendar and Outlook subscribe to it once and keep it up to date by
 *    themselves (a moved or cancelled lesson changes in their calendar too). A one-hour alarm is included.
 *
 * Confirmations
 *  - Every reminder has two buttons: "Yes, I'll be there" and "I can't make it". They open a page with a
 *    real button (e-mail scanners that open links cannot confirm by accident).
 *  - Behind the wheel (fee policy 7): not confirmed → reminder one day before the free-cancellation
 *    deadline, a last call two hours before it, and one more message one hour before the lesson.
 *    "I can't make it" records the exact time and tells the school; the page says whether the fee applies.
 *  - Zoom (policies 1 and 4): the first session is mandatory; after it, up to four absences are allowed and
 *    the school must be told. "I can't make it" shows which absence it would be and alerts the school.
 *    Reminder the day before each session, and one hour before when not answered.
 *  - Staff mark present / absent on the class attendance grid.
 */
class SDSC_Schedule {
	const DBV       = '2';
	const DBO       = 'sdsc_attend_db';
	const META      = '_sdsc_sched';
	const HOOK      = 'sdsc_schedule_tick';
	const PAGE      = 'sdsc-attendance';
	const ALLOWED   = 4;     // absences allowed after the first session (policy 4)

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_attend'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 21 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'save_post_sdsc_class', array( __CLASS__, 'save_meta' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'front' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_post_sdsc_att_save', array( __CLASS__, 'h_save' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'cron' ) );
	}

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(5) NOT NULL DEFAULT '',
			ref bigint(20) unsigned NOT NULL DEFAULT 0,
			sess varchar(10) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			rsvp varchar(4) NOT NULL DEFAULT '',
			rsvp_at datetime DEFAULT NULL,
			actual varchar(8) NOT NULL DEFAULT '',
			actual_by bigint(20) unsigned NOT NULL DEFAULT 0,
			actual_at datetime DEFAULT NULL,
			staff_note varchar(255) NOT NULL DEFAULT '',
			sent varchar(255) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY one (kind,ref,sess,email),
			KEY email (email)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	public static function cron() {
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 90, 'sdsc_5min', self::HOOK ); }
	}

	/* ================================================================ timetable */

	/** The school's usual timetable: 10 sessions, Monday to Friday. Morning 9:00–12:00; evening classes keep the same days. */
	public static function preset( $which = 'am' ) {
		return 'pm' === $which
			? array( 'days' => array( 1, 2, 3, 4, 5 ), 'from' => '18:00', 'to' => '21:15', 'count' => 10, 'remind' => 1, 'skip' => array(), 'extra' => array() )
			: array( 'days' => array( 1, 2, 3, 4, 5 ), 'from' => '09:00', 'to' => '12:00', 'count' => 10, 'remind' => 1, 'skip' => array(), 'extra' => array() );
	}

	public static function sched( $class_id ) {
		$s = get_post_meta( $class_id, self::META, true );
		if ( ! is_array( $s ) ) { // a new class starts from the usual timetable (evening when the title says PM / evening)
			$t = strtolower( (string) get_post_field( 'post_title', $class_id ) );
			return self::preset( preg_match( '/\b(pm|evening|night)\b/', $t ) ? 'pm' : 'am' );
		}
		return wp_parse_args( $s, array( 'days' => array(), 'from' => '', 'to' => '', 'count' => 10, 'remind' => 1, 'skip' => array(), 'extra' => array() ) );
	}

	/** Dates the school is closed (holiday calendar) from a start date on: Y-m-d => 1. */
	public static function closed_from( $start ) {
		$closed = array();
		if ( class_exists( 'SDSC_Holidays' ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			$a = new DateTimeImmutable( $start, wp_timezone() );
			foreach ( SDSC_Holidays::between( $a->modify( '-1 month' ), $a->modify( '+8 months' ) ) as $h ) {
				if ( ! empty( $h['cfg']['closed'] ) ) { $closed[ $h['observed']->format( 'Y-m-d' ) ] = 1; }
			}
		}
		return $closed;
	}

	/** Session list: [ n, date (Y-m-d), start, end (local "Y-m-d H:i:s") ]. Empty until the timetable is filled in. */
	public static function sessions( $class_id ) {
		$s     = self::sched( $class_id );
		// The class form saves "Y-m-d\TH:i"; older classes have "Y-m-d". Only the date matters here.
		$start = substr( (string) get_post_meta( $class_id, '_sdsc_start', true ), 0, 10 );
		$end   = substr( (string) get_post_meta( $class_id, '_sdsc_end', true ), 0, 10 );
		if ( ! $s['days'] || ! $s['from'] || ! $s['to'] || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) { return array(); }
		$dates = self::dates( $s, $start, $end );
		$out   = array();
		foreach ( $dates as $ymd ) { $out[] = array( 'n' => count( $out ) + 1, 'date' => $ymd, 'start' => $ymd . ' ' . $s['from'] . ':00', 'end' => $ymd . ' ' . $s['to'] . ':00' ); }
		return $out;
	}

	/**
	 * Session dates: the class days from the start date, skipping school-closed days and days the office took out
	 * ("skip"), plus days the office added by clicking the calendar ("extra"); the first `count` of them.
	 */
	public static function dates( $s, $start, $end = '' ) {
		$closed = self::closed_from( $start );
		$skip   = array_flip( array_map( 'strval', (array) ( $s['skip'] ?? array() ) ) );
		$extra  = array_filter( array_map( 'strval', (array) ( $s['extra'] ?? array() ) ), function ( $x ) use ( $start ) { return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $x ) && $x >= $start; } );
		$want   = max( 1, (int) $s['count'] );
		$days   = array_map( 'intval', (array) $s['days'] );
		$d      = new DateTimeImmutable( $start, wp_timezone() );
		$lim    = preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $end ) ? substr( $end, 0, 10 ) : $d->modify( '+8 months' )->format( 'Y-m-d' );
		$auto   = array();
		for ( $i = 0; $i < 400 && count( $auto ) + count( $extra ) < $want + count( $skip ) + 5; $i++, $d = $d->modify( '+1 day' ) ) {
			$ymd = $d->format( 'Y-m-d' );
			if ( $ymd > $lim ) { break; }
			if ( ! in_array( (int) $d->format( 'w' ), $days, true ) || isset( $closed[ $ymd ] ) || isset( $skip[ $ymd ] ) ) { continue; }
			$auto[] = $ymd;
		}
		$all = array_values( array_unique( array_merge( $auto, $extra ) ) );
		sort( $all );
		return array_slice( $all, 0, $want );
	}

	public static function metabox() {
		add_meta_box( 'sdsc-sched', 'Timetable & calendar', array( __CLASS__, 'metabox_html' ), 'sdsc_class', 'normal', 'high' );
	}

	public static function metabox_html( $post ) {
		$s    = self::sched( $post->ID );
		$days = array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun' );
		wp_nonce_field( 'sdsc_sched', 'sdsc_sched_nonce' );
		// A start/end saved as a date only (no time) would show as empty in a date-and-time field and be erased on Update.
		echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("input[type=datetime-local]").forEach(function(i){var v=i.getAttribute("value")||"";if(/^\\d{4}-\\d{2}-\\d{2}$/.test(v)&&!i.value){i.value=v+"T00:00";}});});</script>';
		?>
		<p style="margin-top:0">Fill this in once per class. The session dates go into every enrolled student&rsquo;s calendar (Apple, Google, Outlook) and drive the reminders and the attendance sheet. Days marked <em>School closed</em> on the holiday calendar are skipped.</p>
		<p><b>Class days:</b>
			<?php foreach ( $days as $k => $l ) : ?><label style="margin-right:10px"><input type="checkbox" name="sdsc_sched[days][]" value="<?php echo (int) $k; ?>"<?php checked( in_array( $k, array_map( 'intval', $s['days'] ), true ) ); ?>> <?php echo esc_html( $l ); ?></label><?php endforeach; ?>
		</p>
		<p>
			<label>Starts <input type="time" name="sdsc_sched[from]" value="<?php echo esc_attr( $s['from'] ); ?>"></label>
			<label style="margin-left:12px">Ends <input type="time" name="sdsc_sched[to]" value="<?php echo esc_attr( $s['to'] ); ?>"></label>
			<label style="margin-left:12px">Number of sessions <input type="number" min="1" max="60" name="sdsc_sched[count]" value="<?php echo (int) $s['count']; ?>" style="width:70px"></label>
		</p>
		<p><label><input type="checkbox" name="sdsc_sched[remind]" value="1"<?php checked( $s['remind'] ); ?>> Send each student a reminder the day before every session (and one hour before if they have not answered)</label></p>
		<p><b>Usual timetable:</b> <button type="button" class="button button-small" data-sdsc-preset="am">Morning · Mon–Fri · 9:00 AM–12:00 PM · 10 sessions</button> <button type="button" class="button button-small" data-sdsc-preset="pm">Evening · Mon–Fri · 6:00–9:15 PM · 10 sessions</button> <span class="description">— sets the days, times and count above; adjust the evening times if yours differ.</span></p>
		<input type="hidden" name="sdsc_sched[skip]" id="sdsc-sched-skip" value="<?php echo esc_attr( implode( ',', (array) $s['skip'] ) ); ?>">
		<input type="hidden" name="sdsc_sched[extra]" id="sdsc-sched-extra" value="<?php echo esc_attr( implode( ',', (array) $s['extra'] ) ); ?>">
		<?php
		$start0 = substr( (string) get_post_meta( $post->ID, '_sdsc_start', true ), 0, 10 );
		$closed = self::closed_from( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start0 ) ? $start0 : wp_date( 'Y-m-d' ) );
		$hol    = array();
		if ( class_exists( 'SDSC_Holidays' ) ) {
			$a0 = new DateTimeImmutable( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start0 ) ? $start0 : wp_date( 'Y-m-d' ), wp_timezone() );
			foreach ( SDSC_Holidays::between( $a0->modify( '-1 month' ), $a0->modify( '+8 months' ) ) as $h ) { $hol[ $h['observed']->format( 'Y-m-d' ) ] = (string) ( $h['def']['name'] ?? $h['def']['label'] ?? $h['key'] ); }
		}
		?>
		<div id="sdsc-cal" data-closed="<?php echo esc_attr( wp_json_encode( array_keys( $closed ) ) ); ?>" data-hol="<?php echo esc_attr( wp_json_encode( $hol ) ); ?>" style="margin:10px 0 6px"></div>
		<p class="description" style="margin:0 0 10px"><b>Calendar:</b> green days are the sessions (numbered). Click a session day to take it out (the schedule shifts to the next class day); click any other day to add it as a session. Days marked <i>closed</i> are skipped automatically. Save the class to apply.</p>
		<style>
		.sdc-months{display:flex;flex-wrap:wrap;gap:14px}.sdc-m{width:238px}.sdc-m h4{margin:0 0 4px;font-size:13px;text-align:center}
		.sdc-g{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}.sdc-g span{font-size:10px;color:#787c82;text-align:center}
		.sdc-d{position:relative;height:30px;border:1px solid #dcdcde;border-radius:6px;background:#fff;font-size:11.5px;text-align:left;padding:2px 4px;cursor:pointer;color:#1d2327}
		.sdc-d.is-out{visibility:hidden}.sdc-d.is-we{background:#f6f7f7;color:#8c8f94}.sdc-d.is-closed{background:#fff3f3;color:#b32d2e;cursor:not-allowed}
		.sdc-d.is-s{background:#d7f2e1;border-color:#00703c;font-weight:700}.sdc-d.is-s.is-x{background:#fff8e1;border-color:#dba617}
		.sdc-d.is-skip{background:#f0f0f1;text-decoration:line-through;color:#8c8f94}
		.sdc-d .n{position:absolute;right:3px;bottom:1px;font-size:10px;color:#00703c}.sdc-d.is-x .n{color:#8a6100}
		.sdc-d.is-before{opacity:.45;cursor:not-allowed}
		.sdc-leg{font-size:11.5px;color:#646970;margin:6px 0 0}.sdc-leg i{display:inline-block;width:11px;height:11px;border-radius:3px;vertical-align:-1px;margin:0 3px 0 8px;border:1px solid #ccc}
		</style>
		<script>
		(function(){
			var box=document.getElementById('sdsc-cal'); if(!box) return;
			var closed=JSON.parse(box.getAttribute('data-closed')||'[]'), hol=JSON.parse(box.getAttribute('data-hol')||'{}');
			var skipI=document.getElementById('sdsc-sched-skip'), extraI=document.getElementById('sdsc-sched-extra');
			var q=function(n){return document.querySelector('[name="sdsc_sched['+n+']"]');};
			var list=function(i){return i.value?i.value.split(',').filter(Boolean):[];};
			var pad=function(n){return (n<10?'0':'')+n;};
			var ymd=function(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());};
			var startOf=function(){var v=(document.getElementById('sdsc_start')||{}).value||'';return /^\d{4}-\d{2}-\d{2}/.test(v)?v.slice(0,10):'';};
			var endOf=function(){var v=(document.getElementById('sdsc_end')||{}).value||'';return /^\d{4}-\d{2}-\d{2}/.test(v)?v.slice(0,10):'';};
			function compute(){
				var start=startOf(); if(!start) return {};
				var days=[].map.call(document.querySelectorAll('[name="sdsc_sched[days][]"]:checked'),function(c){return +c.value;});
				var want=Math.max(1,+(q('count')||{}).value||10), skip=list(skipI), extra=list(extraI).filter(function(x){return x>=start;});
				var lim=endOf(); var d=new Date(start+'T12:00:00'); var auto=[];
				for(var i=0;i<400&&auto.length+extra.length<want+skip.length+5;i++,d.setDate(d.getDate()+1)){var y=ymd(d);if(lim&&y>lim)break;if(days.indexOf(d.getDay())<0||closed.indexOf(y)>-1||skip.indexOf(y)>-1)continue;auto.push(y);}
				var all=auto.concat(extra).filter(function(v,i,a){return a.indexOf(v)===i;}).sort().slice(0,want), m={};
				all.forEach(function(y,i){m[y]=i+1;}); return m;
			}
			function render(){
				var start=startOf(); if(!start){box.innerHTML='<p class="description">Set the class start date first; the calendar appears here.</p>';return;}
				var sess=compute(), skip=list(skipI), extra=list(extraI);
				var last=start; for(var k in sess){if(k>last)last=k;}
				var first=new Date(start+'T12:00:00'); first.setDate(1); var end=new Date(last+'T12:00:00'); end.setMonth(end.getMonth()+1,1);
				var h='<div class="sdc-months">';
				for(var m=new Date(first);m<end;m.setMonth(m.getMonth()+1)){
					h+='<div class="sdc-m"><h4>'+m.toLocaleString('en-US',{month:'long',year:'numeric'})+'</h4><div class="sdc-g">';
					['S','M','T','W','T','F','S'].forEach(function(x){h+='<span>'+x+'</span>';});
					var dim=new Date(m.getFullYear(),m.getMonth()+1,0).getDate(), off=new Date(m.getFullYear(),m.getMonth(),1).getDay();
					for(var i=0;i<off;i++)h+='<div class="sdc-d is-out"></div>';
					for(var dd=1;dd<=dim;dd++){var y=m.getFullYear()+'-'+pad(m.getMonth()+1)+'-'+pad(dd), dow=new Date(y+'T12:00:00').getDay(), cls='sdc-d';
						if(dow===0||dow===6)cls+=' is-we'; if(closed.indexOf(y)>-1)cls+=' is-closed'; if(y<start)cls+=' is-before';
						if(sess[y])cls+=' is-s'; if(extra.indexOf(y)>-1&&sess[y])cls+=' is-x'; if(skip.indexOf(y)>-1)cls+=' is-skip';
						h+='<div class="'+cls+'" data-d="'+y+'" title="'+(hol[y]?hol[y].replace(/"/g,'&quot;')+(closed.indexOf(y)>-1?' — closed':''):'')+'">'+dd+(sess[y]?'<span class="n">'+sess[y]+'</span>':'')+'</div>';}
					h+='</div></div>';
				}
				h+='</div><p class="sdc-leg"><i style="background:#d7f2e1;border-color:#00703c"></i> session <i style="background:#fff8e1;border-color:#dba617"></i> added by hand <i style="background:#f0f0f1"></i> taken out <i style="background:#fff3f3"></i> school closed</p>';
				box.innerHTML=h;
			}
			box.addEventListener('click',function(e){var c=e.target.closest('.sdc-d'); if(!c||c.classList.contains('is-out')||c.classList.contains('is-closed')||c.classList.contains('is-before'))return;
				var y=c.getAttribute('data-d'), skip=list(skipI), extra=list(extraI), sess=compute();
				if(skip.indexOf(y)>-1){skip.splice(skip.indexOf(y),1);} else if(extra.indexOf(y)>-1){extra.splice(extra.indexOf(y),1);} else if(sess[y]){skip.push(y);} else {extra.push(y);}
				skipI.value=skip.sort().join(','); extraI.value=extra.sort().join(','); render();});
			document.querySelectorAll('[data-sdsc-preset]').forEach(function(b){b.addEventListener('click',function(){var pm=b.getAttribute('data-sdsc-preset')==='pm';
				document.querySelectorAll('[name="sdsc_sched[days][]"]').forEach(function(c){c.checked=+c.value>=1&&+c.value<=5;});
				q('from').value=pm?'18:00':'09:00'; q('to').value=pm?'21:15':'12:00'; q('count').value=10; skipI.value=''; extraI.value=''; render();});});
			['change','input'].forEach(function(ev){document.addEventListener(ev,function(e){if(e.target&&(e.target.id==='sdsc_start'||e.target.id==='sdsc_end'||/sdsc_sched\[(days|count)/.test(e.target.name||'')))render();});});
			render();
		})();
		</script>
		<?php
		$list = self::sessions( $post->ID );
		if ( $list ) {
			echo '<p><b>Sessions:</b> ';
			$bits = array();
			foreach ( $list as $x ) { $bits[] = '<span style="display:inline-block;margin:2px 6px 2px 0;padding:2px 8px;border-radius:999px;background:#f0f0f1">' . (int) $x['n'] . '. ' . esc_html( wp_date( 'D M j', SDSC_Lessons::ts( $x['start'] ) ) ) . '</span>'; }
			echo implode( '', $bits ) . '</p>'; // phpcs:ignore
			if ( count( $list ) < (int) $s['count'] ) { echo '<p style="color:#b32d2e">Only ' . count( $list ) . ' sessions fit between the start and end dates.</p>'; }
		} else {
			echo '<p style="color:#646970">Sessions appear here after the start date, days and times are saved.</p>';
		}
		do_action( 'sdsc_class_timetable_fields', $post );
	}

	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['sdsc_sched_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sdsc_sched_nonce'] ), 'sdsc_sched' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$in   = (array) wp_unslash( $_POST['sdsc_sched'] ?? array() ); // phpcs:ignore -- sanitised below
		$time  = function ( $v ) { return preg_match( '/^\d{2}:\d{2}$/', (string) $v ) ? $v : ''; };
		$dates = function ( $v ) { return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', (string) $v ) ), function ( $x ) { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $x ); } ) ) ); };
		update_post_meta( $post_id, self::META, array(
			'days'   => array_values( array_intersect( array_map( 'intval', (array) ( $in['days'] ?? array() ) ), range( 0, 6 ) ) ),
			'from'   => $time( $in['from'] ?? '' ),
			'to'     => $time( $in['to'] ?? '' ),
			'count'  => min( 60, max( 1, (int) ( $in['count'] ?? 10 ) ) ),
			'remind' => empty( $in['remind'] ) ? 0 : 1,
			'skip'   => $dates( $in['skip'] ?? '' ),
			'extra'  => $dates( $in['extra'] ?? '' ),
		) );
	}

	/* ================================================================ people */

	/** Students of a class (newest registration per e-mail). */
	public static function roster( $class_id ) {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Registration' ) ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT first_name, last_name, email, phone FROM ' . SDSC_Registration::table() . ' WHERE class_id = %d ORDER BY id DESC', $class_id ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$e = strtolower( trim( $r['email'] ) );
			if ( ! is_email( $e ) || isset( $out[ $e ] ) ) { continue; }
			if ( class_exists( 'SDSC_Transfer' ) && ! SDSC_Transfer::classroom( $class_id, $e ) ) { continue; } // behind-the-wheel-only students are not in the classroom
			$out[ $e ] = array( 'email' => $e, 'first' => self::nice( $r['first_name'] ), 'last' => self::nice( $r['last_name'] ), 'phone' => $r['phone'], 'lang' => self::lang( $e ) );
		}
		uasort( $out, function ( $a, $b ) { return strcasecmp( $a['last'] . $a['first'], $b['last'] . $b['first'] ); } );
		return $out;
	}

	protected static function nice( $s ) {
		$s = trim( (string) $s );
		return ( $s === strtoupper( $s ) || $s === strtolower( $s ) ) ? mb_convert_case( strtolower( $s ), MB_CASE_TITLE, 'UTF-8' ) : $s;
	}

	public static function lang( $email ) {
		if ( class_exists( 'SDSC_Outreach' ) ) {
			foreach ( SDSC_Outreach::contacts() as $c ) { if ( $c['email'] === $email ) { return $c['lang']; } }
		}
		return 'en';
	}

	/** Class ids this e-mail registered for. */
	public static function classes_of( $email ) {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Registration' ) ) { return array(); }
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT class_id FROM ' . SDSC_Registration::table() . ' WHERE email = %s AND class_id > 0', strtolower( $email ) ) ) ); // phpcs:ignore
	}

	/** Class name with its code: the public code for students, the real course number when $staff. */
	public static function class_name( $class_id, $staff = false ) {
		$code = $staff ? (string) get_post_meta( $class_id, '_sdsc_course_code', true ) : ( class_exists( 'SDSC_Registration' ) && method_exists( 'SDSC_Registration', 'public_code' ) ? SDSC_Registration::public_code( $class_id ) : '' );
		$t    = html_entity_decode( (string) get_post_field( 'post_title', $class_id ), ENT_QUOTES, 'UTF-8' );
		return trim( $t . ( $code && false === strpos( $t, $code ) ? ' (' . $code . ')' : '' ) );
	}

	/* ================================================================ answers */

	public static function row( $kind, $ref, $sess, $email ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE kind = %s AND ref = %d AND sess = %s AND email = %s', $kind, $ref, $sess, strtolower( $email ) ), ARRAY_A ); // phpcs:ignore
	}

	public static function put( $kind, $ref, $sess, $email, $data ) {
		global $wpdb;
		$email = strtolower( $email );
		$row   = self::row( $kind, $ref, $sess, $email );
		if ( $row ) { $wpdb->update( self::table(), $data, array( 'id' => $row['id'] ) ); }
		else { $wpdb->insert( self::table(), array_merge( array( 'kind' => $kind, 'ref' => $ref, 'sess' => $sess, 'email' => $email ), $data ) ); }
	}

	protected static function flag( $row, $f ) { return $row && false !== strpos( ',' . $row['sent'] . ',', ',' . $f . ',' ); }

	protected static function mark( $kind, $ref, $sess, $email, $f ) {
		$row = self::row( $kind, $ref, $sess, $email );
		self::put( $kind, $ref, $sess, $email, array( 'sent' => trim( ( $row['sent'] ?? '' ) . ',' . $f, ',' ) ) );
	}

	/** Absences counted against the four allowed (sessions 2+): marked absent, or said "can't" and not marked present. */
	public static function absences( $class_id, $email ) {
		global $wpdb;
		$first = self::sessions( $class_id )[0]['date'] ?? '';
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT sess, rsvp, actual FROM ' . self::table() . " WHERE kind = 'zoom' AND ref = %d AND email = %s", $class_id, strtolower( $email ) ), ARRAY_A ); // phpcs:ignore
		$n     = 0;
		$miss1 = false;
		$cov   = class_exists( 'SDSC_Transfer' ) ? SDSC_Transfer::covered( $class_id, $email ) : array(); // sessions done at a previous school
		foreach ( $rows as $r ) {
			if ( in_array( $r['sess'], $cov, true ) ) { continue; }
			$abs = 'absent' === $r['actual'] || ( 'no' === $r['rsvp'] && '' === $r['actual'] && $r['sess'] <= current_time( 'Y-m-d' ) );
			if ( ! $abs ) { continue; }
			if ( $r['sess'] === $first ) { $miss1 = true; } else { $n++; }
		}
		return array( $n, $miss1 );
	}

	/* ================================================================ tokens & links */

	protected static function b64( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }
	protected static function unb64( $s ) { return base64_decode( strtr( $s, '-_', '+/' ) ); }
	protected static function sig( $s ) { return substr( hash_hmac( 'sha256', $s, wp_salt( 'nonce' ) . 'sdsc-att' ), 0, 16 ); }

	public static function att_url( $kind, $ref, $sess, $email ) {
		$p = $kind . '|' . (int) $ref . '|' . $sess . '|' . strtolower( $email );
		$u = add_query_arg( 'sdsc_att', self::b64( $p ) . '.' . self::sig( $p ), home_url( '/' ) );
		return class_exists( 'SDSC_Short' ) ? SDSC_Short::make( $u ) : $u;
	}

	public static function cal_token( $email ) {
		$e = strtolower( trim( $email ) );
		return self::b64( $e ) . '.' . self::sig( 'cal|' . $e );
	}

	public static function cal_page( $email ) {
		$u = add_query_arg( 'sdsc_cal', self::cal_token( $email ), home_url( '/' ) );
		return class_exists( 'SDSC_Short' ) ? SDSC_Short::make( $u ) : $u;
	}
	public static function ics_url( $email ) { return add_query_arg( 'sdsc_ics', self::cal_token( $email ), home_url( '/' ) ); }

	protected static function read_cal( $tok ) {
		if ( ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{16})$/', (string) $tok, $m ) ) { return ''; }
		$e = strtolower( (string) self::unb64( $m[1] ) );
		return is_email( $e ) && hash_equals( self::sig( 'cal|' . $e ), $m[2] ) ? $e : '';
	}

	protected static function read_att( $tok ) {
		if ( ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{16})$/', (string) $tok, $m ) ) { return null; }
		$p = (string) self::unb64( $m[1] );
		if ( ! hash_equals( self::sig( $p ), $m[2] ) ) { return null; }
		$x = explode( '|', $p );
		return 4 === count( $x ) && in_array( $x[0], array( 'btw', 'zoom' ), true ) ? array( 'kind' => $x[0], 'ref' => (int) $x[1], 'sess' => $x[2], 'email' => $x[3] ) : null;
	}

	/** The two buttons for an e-mail (table-based so every mail app shows them). */
	public static function buttons_html( $kind, $ref, $sess, $email, $lang ) {
		$u  = self::att_url( $kind, $ref, $sess, $email );
		$es = 'es' === $lang;
		$b  = function ( $href, $label, $bg, $fg ) { return '<td style="padding:4px"><a href="' . esc_url( $href ) . '" style="display:inline-block;padding:13px 22px;border-radius:999px;background:' . $bg . ';color:' . $fg . ';font:700 15px Arial,Helvetica,sans-serif;text-decoration:none">' . esc_html( $label ) . '</a></td>'; };
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:6px auto 18px"><tr>'
			. $b( add_query_arg( 'a', 'yes', $u ), $es ? '✓ Sí, estaré allí' : '✓ Yes, I’ll be there', '#00703c', '#ffffff' )
			. $b( add_query_arg( 'a', 'no', $u ), $es ? 'No puedo asistir' : 'I can’t make it', '#f0f0f1', '#15191d' )
			. '</tr></table>';
	}

	public static function cal_link_html( $email, $lang ) {
		return '<p style="margin:0 0 16px;text-align:center"><a href="' . esc_url( self::cal_page( $email ) ) . '" style="color:#00703c;font-weight:700">📅 ' . ( 'es' === $lang ? 'Agregar todas sus clases a su calendario (Apple, Google, Outlook)' : 'Add all your classes to your calendar (Apple, Google, Outlook)' ) . '</a></p>';
	}

	/* ================================================================ front pages */

	public static function front() {
		if ( isset( $_GET['sdsc_ics'] ) ) { self::serve_ics( sanitize_text_field( wp_unslash( $_GET['sdsc_ics'] ) ) ); } // phpcs:ignore
		if ( isset( $_GET['sdsc_cal'] ) ) { self::page_cal( sanitize_text_field( wp_unslash( $_GET['sdsc_cal'] ) ) ); } // phpcs:ignore
		if ( isset( $_GET['sdsc_att'] ) ) { self::page_att( sanitize_text_field( wp_unslash( $_GET['sdsc_att'] ) ) ); } // phpcs:ignore
	}

	protected static function ics_esc( $s ) {
		return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), (string) $s );
	}

	protected static function ics_fold( $line ) {
		$out = '';
		while ( strlen( $line ) > 74 ) {
			$cut = 74;
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) { $cut--; }   // never split a UTF-8 character
			$out .= substr( $line, 0, $cut ) . "\r\n ";
			$line = substr( $line, $cut );
		}
		return $out . $line . "\r\n";
	}

	/** All events of one student: [uid, start ts, end ts, title, place, description, url, cancelled]. */
	public static function events( $email ) {
		global $wpdb;
		$ev = array();
		foreach ( self::classes_of( $email ) as $cid ) {
			if ( 'sdsc_class' !== get_post_type( $cid ) ) { continue; }
			$st   = (string) get_post_meta( $cid, '_sdsc_status', true );
			$zoom = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
			$list = self::sessions( $cid );
			foreach ( $list as $x ) {
				$ev[] = array(
					'uid'   => 'sdsc-zoom-' . $cid . '-' . $x['date'],
					'a'     => SDSC_Lessons::ts( $x['start'] ),
					'b'     => SDSC_Lessons::ts( $x['end'] ),
					'title' => 'Driver’s Ed class ' . $x['n'] . ' of ' . count( $list ) . ' (Zoom) — Sam’s Driving School',
					'place' => $zoom ?: 'Zoom',
					'desc'  => ( 1 === $x['n'] ? "Session 1 is mandatory (MVA rule).\n" : '' ) . 'Camera and microphone on, at a desk, for the whole class. Can’t attend? Tell the school: ' . SDSC_Lessons::phone() . "\n" . self::att_url( 'zoom', $cid, $x['date'], $email ),
					'url'   => $zoom,
					'off'   => 'cancelled' === $st,
				);
			}
		}
		if ( class_exists( 'SDSC_Lessons' ) ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . ' WHERE email = %s', strtolower( $email ) ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $l ) {
				$a    = SDSC_Lessons::ts( $l['starts_at'] );
				$ev[] = array(
					'uid'   => 'sdsc-btw-' . $l['id'],
					'a'     => $a,
					'b'     => $a + (int) $l['minutes'] * 60,
					'title' => SDSC_Lessons::lesson_label( $l, 'en' ) . ' — Sam’s Driving School',
					'place' => $l['place'],
					'desc'  => 'Bring your valid learner’s permit. Free cancellation until ' . SDSC_Lessons::when( SDSC_Lessons::deadline( $l ), 'en' ) . ' — call or text ' . SDSC_Lessons::phone() . ".\n" . self::att_url( 'btw', $l['id'], '', $email ),
					'url'   => '',
					'off'   => ! in_array( $l['status'], array( 'booked', 'done' ), true ),
				);
			}
		}
		usort( $ev, function ( $x, $y ) { return $x['a'] <=> $y['a']; } );
		return $ev;
	}

	protected static function serve_ics( $tok ) {
		$email = self::read_cal( $tok );
		if ( ! $email ) { status_header( 404 ); exit; }
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$now  = gmdate( 'Ymd\THis\Z' );
		$o    = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Sam's Driving School//Classes//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
			. self::ics_fold( 'X-WR-CALNAME:' . self::ics_esc( 'Sam’s Driving School' ) )
			. "X-WR-TIMEZONE:America/New_York\r\nREFRESH-INTERVAL;VALUE=DURATION:PT4H\r\nX-PUBLISHED-TTL:PT4H\r\n";
		foreach ( self::events( $email ) as $e ) {
			$o .= "BEGIN:VEVENT\r\n" . 'UID:' . $e['uid'] . '@' . $host . "\r\nDTSTAMP:" . $now . "\r\n"
				. 'DTSTART:' . gmdate( 'Ymd\THis\Z', $e['a'] ) . "\r\nDTEND:" . gmdate( 'Ymd\THis\Z', $e['b'] ) . "\r\n"
				. self::ics_fold( 'SUMMARY:' . self::ics_esc( $e['title'] ) )
				. self::ics_fold( 'LOCATION:' . self::ics_esc( $e['place'] ) )
				. self::ics_fold( 'DESCRIPTION:' . self::ics_esc( $e['desc'] ) )
				. ( $e['url'] ? self::ics_fold( 'URL:' . $e['url'] ) : '' )
				. 'STATUS:' . ( $e['off'] ? 'CANCELLED' : 'CONFIRMED' ) . "\r\n"
				. ( $e['off'] ? '' : "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nTRIGGER:-PT1H\r\nEND:VALARM\r\n" )
				. "END:VEVENT\r\n";
		}
		$o .= "END:VCALENDAR\r\n";
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: ' . ( isset( $_GET['dl'] ) ? 'attachment' : 'inline' ) . '; filename="sams-driving-school.ics"' ); // phpcs:ignore
		echo $o; // phpcs:ignore -- calendar text, escaped above
		exit;
	}

	protected static function shell( $title, $inner, $lang ) {
		header( 'Cache-Control: private, no-store, max-age=0' );
		$i = SDSC_Mailer::info();
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo '<!doctype html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title . ' · ' . $i['name'] ) . '</title><style>'
			. ':root{--g:#00703c;--y:#ffc20e;--ink:#15191d;--mut:#5b646d}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:#f6f4ee;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink)}'
			. '.c{width:100%;max-width:500px;background:#fff;border-radius:18px;box-shadow:0 18px 40px -18px rgba(0,0,0,.25);overflow:hidden}.top{padding:20px 26px;border-bottom:4px solid var(--g)}.top img{display:block;max-width:180px;height:auto}.top b{font-size:20px}'
			. '.road{height:6px;background:repeating-linear-gradient(90deg,var(--y) 0 26px,#2d3238 26px 46px)}.in{padding:26px}h1{margin:0 0 10px;font-size:24px;line-height:1.25;color:var(--g)}p{margin:0 0 14px;color:#2b3238}.em{font-size:42px;line-height:1}'
			. '.facts{margin:0 0 16px;padding:12px 16px;border-radius:12px;background:#f6f4ee}.facts b{display:block}'
			. '.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin:10px 0 0;padding:14px;border:0;border-radius:999px;background:var(--g);color:#fff;font:700 16px/1.2 inherit;font-family:inherit;text-decoration:none;cursor:pointer}'
			. '.btn--2{background:#f0f0f1;color:var(--ink)}.btn:focus-visible{outline:3px solid var(--y);outline-offset:2px}.warn{padding:12px 14px;border-radius:12px;background:#fff8e1;border-left:4px solid var(--y)}.ok{padding:12px 14px;border-radius:12px;background:#e7f5ec;border-left:4px solid var(--g)}'
			. '.f{padding:14px 26px;background:#fafaf7;font-size:13px;color:var(--mut)}.small{font-size:13px;color:var(--mut)}code{word-break:break-all;font-size:12px}'
			. '</style></head><body><main class="c"><div class="top">' . ( $i['logo'] ? '<img src="' . esc_url( $i['logo'] ) . '" alt="' . esc_attr( $i['name'] ) . '">' : '<b>' . esc_html( $i['name'] ) . '</b>' ) . '</div><div class="road"></div><div class="in">'
			. $inner . '</div><div class="f">' . esc_html( $i['name'] ) . ' · ' . esc_html( $i['phones'][0] ) . '</div></main></body></html>';
		exit;
	}

	protected static function page_cal( $tok ) {
		$email = self::read_cal( $tok );
		$lang  = $email ? self::lang( $email ) : 'en';
		$es    = 'es' === $lang;
		if ( ! $email ) { self::shell( 'Calendar', '<h1>Link not valid</h1><p>Please use the link from your latest e-mail.</p>', 'en' ); }
		$https  = self::ics_url( $email );
		$webcal = preg_replace( '#^https?://#', 'webcal://', $https );
		$n      = count( array_filter( self::events( $email ), function ( $e ) { return ! $e['off'] && $e['b'] > time(); } ) );
		$inner  = '<div class="em">📅</div><h1>' . ( $es ? 'Sus clases en su calendario' : 'Your classes in your calendar' ) . '</h1>'
			. '<p>' . ( $es ? 'Suscríbase una vez: todas sus clases de Zoom y de manejo aparecen en su calendario, con un aviso una hora antes. Si una clase cambia o se cancela, su calendario se actualiza solo.' : 'Subscribe once: all your Zoom classes and driving lessons show up in your calendar, with an alert one hour before. If a class moves or is cancelled, your calendar updates by itself.' ) . '</p>'
			. '<p class="small">' . sprintf( $es ? '%d clases próximas.' : '%d upcoming classes.', $n ) . '</p>'
			. '<a class="btn" href="' . esc_attr( $webcal ) . '"> Apple Calendar (iPhone, iPad, Mac)</a>'
			. '<a class="btn" href="' . esc_url( 'https://calendar.google.com/calendar/render?cid=' . rawurlencode( $webcal ) ) . '" target="_blank" rel="noopener">Google Calendar</a>'
			. '<a class="btn" href="' . esc_url( 'https://outlook.live.com/calendar/0/addfromweb?url=' . rawurlencode( $https ) . '&name=' . rawurlencode( 'Sam’s Driving School' ) ) . '" target="_blank" rel="noopener">Outlook</a>'
			. '<a class="btn btn--2" href="' . esc_url( add_query_arg( 'dl', 1, $https ) ) . '">' . ( $es ? 'Descargar archivo .ics' : 'Download .ics file' ) . '</a>'
			. '<p class="small" style="margin-top:16px">' . ( $es ? 'Otra aplicación: agregue un calendario «desde URL» con esta dirección:' : 'Any other calendar app: add a calendar “from URL” with this address:' ) . '<br><code>' . esc_html( $https ) . '</code></p>'
			. '<p class="small">' . ( $es ? 'Este enlace es personal; no lo comparta.' : 'This link is personal; please don’t share it.' ) . '</p>';
		self::shell( $es ? 'Calendario' : 'Calendar', $inner, $lang );
	}

	/** "Yes, I'll be there" / "I can't make it" page. The answer is saved only from the button (POST). */
	protected static function page_att( $tok ) {
		$t = self::read_att( $tok );
		if ( ! $t ) { self::shell( 'Link', '<h1>Link not valid</h1><p>Please use the link from your latest message, or call ' . esc_html( SDSC_Lessons::phone() ) . '.</p>', 'en' ); }
		$lang = self::lang( $t['email'] );
		$es   = 'es' === $lang;
		$ph   = esc_html( SDSC_Lessons::phone() );
		$want = isset( $_REQUEST['a'] ) && 'no' === $_REQUEST['a'] ? 'no' : 'yes'; // phpcs:ignore
		$post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['go'] ); // phpcs:ignore

		// What is it?
		if ( 'btw' === $t['kind'] ) {
			$l = SDSC_Lessons::lesson( $t['ref'] );
			if ( ! $l || strtolower( $l['email'] ) !== $t['email'] ) { self::shell( 'Lesson', '<h1>Lesson not found</h1><p>Please call ' . $ph . '.</p>', $lang ); }
			$start  = SDSC_Lessons::ts( $l['starts_at'] );
			$what   = SDSC_Lessons::lesson_label( $l, $lang );
			$when   = SDSC_Lessons::when( $l['starts_at'], $lang );
			$place  = $l['place'];
			$closed = 'booked' !== $l['status'];
		} else {
			$list = self::sessions( $t['ref'] );
			$x    = null;
			foreach ( $list as $s ) { if ( $s['date'] === $t['sess'] ) { $x = $s; } }
			if ( ! $x ) { self::shell( 'Class', '<h1>Class not found</h1><p>Please call ' . $ph . '.</p>', $lang ); }
			$start  = SDSC_Lessons::ts( $x['start'] );
			$what   = ( $es ? 'Clase de Zoom ' : 'Zoom class ' ) . $x['n'] . ( $es ? ' de ' : ' of ' ) . count( $list ) . ' — ' . self::class_name( $t['ref'] );
			$when   = SDSC_Lessons::when( $x['start'], $lang );
			$place  = 'Zoom';
			$closed = false;
		}
		$facts = '<div class="facts"><b>' . esc_html( $what ) . '</b>' . esc_html( $when ) . '<br>' . esc_html( $place ) . '</div>';
		if ( $closed ) { self::shell( $es ? 'Clase' : 'Lesson', '<h1>' . ( $es ? 'Esta clase ya no está activa' : 'This lesson is no longer active' ) . '</h1>' . $facts . '<p>' . ( $es ? 'Preguntas: ' : 'Questions: ' ) . $ph . '</p>', $lang ); }
		if ( $start < time() - HOUR_IN_SECONDS ) { self::shell( $es ? 'Clase' : 'Lesson', '<h1>' . ( $es ? 'Esta clase ya pasó' : 'This class has already taken place' ) . '</h1>' . $facts, $lang ); }

		// Policy text for "can't make it".
		$policy = '';
		$level  = 'warn';
		if ( 'btw' === $t['kind'] ) {
			$dl     = SDSC_Lessons::ts( SDSC_Lessons::deadline( $l ) );
			$fee    = '$' . SDSC_Lessons::money( SDSC_Lessons::settings()['fee'] );
			$policy = time() < $dl
				? ( $es ? 'Nos avisa a tiempo (antes del ' . esc_html( SDSC_Lessons::when( SDSC_Lessons::deadline( $l ), 'es' ) ) . '), así que <b>no hay cargo</b>. Le llamaremos para elegir otra fecha.' : 'You are telling us in time (before ' . esc_html( SDSC_Lessons::when( SDSC_Lessons::deadline( $l ), 'en' ) ) . '), so there is <b>no fee</b>. We will call you to pick a new date.' )
				: ( $es ? 'Faltan menos de ' . (int) SDSC_Lessons::settings()['hours'] . ' horas para la clase. Según nuestra política se aplica un cargo de <b>' . $fee . '</b>. La escuela revisará su aviso.' : 'The lesson is less than ' . (int) SDSC_Lessons::settings()['hours'] . ' hours away. Under our policy a <b>' . $fee . '</b> fee applies. The school will review your notice.' );
			if ( time() < $dl ) { $level = 'ok'; }
		} else {
			$first = 1 === $x['n'];
			list( $used, $miss1 ) = self::absences( $t['ref'], $t['email'] );
			$row  = self::row( 'zoom', $t['ref'], $t['sess'], $t['email'] );
			$this_one = ! ( $row && 'no' === $row['rsvp'] );
			$after    = $used + ( $this_one && $t['sess'] > current_time( 'Y-m-d' ) ? 1 : 0 );
			if ( $first ) {
				$policy = $es ? '<b>La primera clase es obligatoria</b> según las reglas de la MVA. Si no asiste, la inscripción se cancela automáticamente y no hay recuperación. Llámenos hoy al ' . $ph . '.' : '<b>The first class is mandatory</b> under MVA rules. Missing it cancels the enrollment automatically, with no make-up. Please call us today at ' . $ph . '.';
			} elseif ( $after > self::ALLOWED ) {
				$policy = $es ? 'Esta sería la ausencia número <b>' . $after . '</b>. Se permiten ' . self::ALLOWED . ' después de la primera clase; con más no puede continuar en el programa. Llámenos al ' . $ph . '.' : 'This would be absence number <b>' . $after . '</b>. ' . self::ALLOWED . ' are allowed after the first class; with more you cannot continue in the program. Please call us at ' . $ph . '.';
			} else {
				$policy = $es ? 'Esta será la ausencia <b>' . $after . ' de ' . self::ALLOWED . '</b> permitidas después de la primera clase. Gracias por avisarnos.' : 'This will be absence <b>' . $after . ' of the ' . self::ALLOWED . '</b> allowed after the first class. Thank you for letting us know.';
				$level  = 'ok';
			}
		}

		if ( $post ) {
			$ans = 'no' === ( $_POST['a'] ?? '' ) ? 'no' : 'yes'; // phpcs:ignore
			$old = self::row( $t['kind'], $t['ref'], $t['sess'], $t['email'] );
			self::put( $t['kind'], $t['ref'], $t['sess'], $t['email'], array( 'rsvp' => $ans, 'rsvp_at' => current_time( 'mysql' ), 'note' => sanitize_text_field( wp_unslash( $_POST['why'] ?? '' ) ) ) ); // phpcs:ignore
			if ( 'btw' === $t['kind'] ) { SDSC_Lessons::add_log( $t['ref'], 'yes' === $ans ? 'Student confirmed: will be there' : 'Student said they cannot come (via the link)' ); }
			if ( 'no' === $ans && ( ! $old || 'no' !== $old['rsvp'] ) ) { self::alert_staff( $t, $what, $when, wp_strip_all_tags( $policy ), sanitize_text_field( wp_unslash( $_POST['why'] ?? '' ) ) ); } // phpcs:ignore
			$inner = 'yes' === $ans
				? '<div class="em">✅</div><h1>' . ( $es ? '¡Gracias! Le esperamos.' : 'Thank you! See you there.' ) . '</h1>' . $facts . ( 'btw' === $t['kind'] ? '<p>' . ( $es ? 'Traiga su permiso de aprendizaje válido.' : 'Please bring your valid learner’s permit.' ) . '</p>' : '<p>' . ( $es ? 'Con cámara y micrófono encendidos, en un escritorio, toda la clase.' : 'Camera and microphone on, at a desk, for the whole class.' ) . '</p>' )
				: '<div class="em">📝</div><h1>' . ( $es ? 'Recibimos su aviso' : 'We got your message' ) . '</h1>' . $facts . '<p class="' . $level . '">' . $policy . '</p>';
			$inner .= '<a class="btn btn--2" href="' . esc_url( self::cal_page( $t['email'] ) ) . '">📅 ' . ( $es ? 'Agregar mis clases al calendario' : 'Add my classes to my calendar' ) . '</a>';
			self::shell( $es ? 'Gracias' : 'Thank you', $inner, $lang );
		}

		$form = function ( $a, $label, $cls, $extra = '' ) use ( $tok ) {
			return '<form method="post" action="' . esc_url( add_query_arg( 'sdsc_att', $tok, home_url( '/' ) ) ) . '">' . $extra . '<input type="hidden" name="a" value="' . esc_attr( $a ) . '"><input type="hidden" name="go" value="1"><button class="btn ' . $cls . '">' . esc_html( $label ) . '</button></form>';
		};
		if ( 'no' === $want ) {
			$inner = '<div class="em">🙁</div><h1>' . ( $es ? '¿No puede asistir?' : 'Can’t make it?' ) . '</h1>' . $facts . '<p class="' . $level . '">' . $policy . '</p>'
				. $form( 'no', $es ? 'Avisar a la escuela que no puedo asistir' : 'Tell the school I can’t come', '', '<label class="small" for="why">' . ( $es ? 'Motivo (opcional)' : 'Reason (optional)' ) . '</label><input id="why" name="why" maxlength="200" style="width:100%;padding:12px;border:1.5px solid #c8cdd2;border-radius:12px;font:inherit">' )
				. $form( 'yes', $es ? 'Sí podré asistir' : 'Actually, I’ll be there', 'btn--2' );
		} else {
			$inner = '<div class="em">🚗</div><h1>' . ( $es ? 'Confirme su asistencia' : 'Confirm you’ll be there' ) . '</h1>' . $facts
				. $form( 'yes', $es ? '✓ Sí, estaré allí' : '✓ Yes, I’ll be there', '' )
				. '<a class="btn btn--2" href="' . esc_url( add_query_arg( array( 'sdsc_att' => $tok, 'a' => 'no' ), home_url( '/' ) ) ) . '">' . ( $es ? 'No puedo asistir' : 'I can’t make it' ) . '</a>';
		}
		self::shell( $es ? 'Asistencia' : 'Attendance', $inner, $lang );
	}

	protected static function alert_staff( $t, $what, $when, $policy, $why ) {
		$to = get_option( 'admin_email' );
		if ( class_exists( 'SDSC_Mailer' ) && method_exists( 'SDSC_Mailer', 'opts' ) ) { $o = SDSC_Mailer::opts(); if ( ! empty( $o['staff'] ) ) { $to = $o['staff']; } }
		$name = $t['email'];
		foreach ( class_exists( 'SDSC_Outreach' ) ? SDSC_Outreach::contacts() : array() as $c ) { if ( $c['email'] === $t['email'] ) { $name = trim( $c['first'] . ' ' . $c['last'] ) . ' (' . $t['email'] . ')'; break; } }
		$link = 'btw' === $t['kind'] ? admin_url( 'admin.php?page=sdsc-lessons&tab=schedule&id=' . $t['ref'] ) : admin_url( 'admin.php?page=' . self::PAGE . '&class=' . $t['ref'] );
		SDSC_Mailer::admin_report( array(
			'to'         => $to,
			'subject_en' => 'Can’t attend: ' . $name,
			'subject_fa' => 'غیبت اعلام‌شده',
			'title_en'   => $name . ' cannot come',
			'title_fa'   => $name . ' نمی‌تواند بیاید',
			'lead_en'    => 'The student told us through the attendance link that they cannot come.',
			'lead_fa'    => 'شاگرد از طریق لینک حضور اعلام کرد که نمی‌تواند بیاید.',
			'rows'       => array(
				array( 'en' => 'Student', 'fa' => 'شاگرد', 'v' => $name, 'ltr' => true ),
				array( 'en' => 'What', 'fa' => 'جلسه', 'v' => $what, 'ltr' => true ),
				array( 'en' => 'When', 'fa' => 'زمان جلسه', 'v' => $when, 'ltr' => true ),
				array( 'en' => 'Told us at', 'fa' => 'زمان اعلام', 'v' => wp_date( 'l, F j, g:i a' ), 'ltr' => true ),
				array( 'en' => 'Reason', 'fa' => 'دلیل', 'v' => $why, 'ltr' => true ),
				array( 'en' => 'Policy', 'fa' => 'قانون مربوط', 'v' => $policy, 'ltr' => true ),
			),
			'buttons'    => array( array( 'en' => 'Open it in the dashboard', 'fa' => 'باز کردن در داشبورد', 'url' => $link ) ),
		) );
	}

	/* ================================================================ reminders */

	public static function tick() {
		if ( get_transient( 'sdsc_sched_lock' ) ) { return; }
		set_transient( 'sdsc_sched_lock', 1, 240 );
		try {
			if ( class_exists( 'SDSC_Mailer' ) && SDSC_Mailer::ready() ) {
				$budget = 40;
				self::btw_reminders( $budget );
				self::zoom_reminders( $budget );
			}
		} finally {
			delete_transient( 'sdsc_sched_lock' );
		}
	}

	/** BTW, not confirmed yet: last call 2 h before the free-cancellation deadline, and one hour before the lesson. (The day-before-deadline reminder is SDSC_Lessons::tick.) */
	protected static function btw_reminders( &$budget ) {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Lessons' ) ) { return; }
		$now  = time();
		$hrs  = (int) SDSC_Lessons::settings()['hours'];
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . " WHERE status = 'booked' AND conf_at IS NOT NULL AND starts_at > %s AND starts_at < %s", current_time( 'mysql' ), wp_date( 'Y-m-d H:i:s', $now + ( $hrs + 3 ) * HOUR_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $l ) {
			if ( $budget <= 0 ) { return; }
			$r = self::row( 'btw', $l['id'], '', $l['email'] );
			if ( $r && in_array( $r['rsvp'], array( 'yes', 'no' ), true ) ) { continue; }
			$start = SDSC_Lessons::ts( $l['starts_at'] );
			$dl    = $start - $hrs * HOUR_IN_SECONDS;
			if ( $now >= $dl - 2 * HOUR_IN_SECONDS && $now < $dl && ! self::flag( $r, 'last' ) && SDSC_Lessons::ts( $l['conf_at'] ) < $dl - 3 * HOUR_IN_SECONDS ) {
				SDSC_Lessons::mail( 'last', $l );
				self::mark( 'btw', $l['id'], '', $l['email'], 'last' );
				$budget--;
			} elseif ( $now >= $start - 65 * MINUTE_IN_SECONDS && $now < $start - 20 * MINUTE_IN_SECONDS && ! self::flag( $r, 'soon' ) ) {
				SDSC_Lessons::mail( 'soon', $l );
				self::mark( 'btw', $l['id'], '', $l['email'], 'soon' );
				$budget--;
			}
		}
	}

	/** Zoom: the day before each session (from 9 am school time), and one hour before when not answered. */
	protected static function zoom_reminders( &$budget ) {
		$q = new WP_Query( array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'posts_per_page' => 50, 'fields' => 'ids', 'meta_key' => self::META, 'no_found_rows' => true ) ); // phpcs:ignore
		$now   = time();
		$today = current_time( 'Y-m-d' );
		$tom   = wp_date( 'Y-m-d', $now + DAY_IN_SECONDS );
		foreach ( $q->posts as $cid ) {
			$s = self::sched( $cid );
			if ( ! $s['remind'] || in_array( get_post_meta( $cid, '_sdsc_status', true ), array( 'cancelled', 'completed' ), true ) ) { continue; }
			$list = self::sessions( $cid );
			foreach ( $list as $x ) {
				$start = SDSC_Lessons::ts( $x['start'] );
				$day   = $x['date'] === $tom && (int) current_time( 'G' ) >= 9;
				$soon  = $x['date'] === $today && $now >= $start - 65 * MINUTE_IN_SECONDS && $now < $start - 20 * MINUTE_IN_SECONDS;
				if ( ! $day && ! $soon ) { continue; }
				foreach ( self::roster( $cid ) as $p ) {
					if ( $budget <= 0 ) { return; }
					$r = self::row( 'zoom', $cid, $x['date'], $p['email'] );
					if ( $day && ! self::flag( $r, 'day' ) ) {
						self::zoom_mail( 'day', $cid, $x, count( $list ), $p );
						self::mark( 'zoom', $cid, $x['date'], $p['email'], 'day' );
						$budget--;
					} elseif ( $soon && ! self::flag( $r, 'soon' ) && ! ( $r && in_array( $r['rsvp'], array( 'yes', 'no' ), true ) ) ) {
						self::zoom_mail( 'soon', $cid, $x, count( $list ), $p );
						self::mark( 'zoom', $cid, $x['date'], $p['email'], 'soon' );
						$budget--;
					}
				}
			}
		}
	}

	public static function zoom_mail( $kind, $cid, $x, $total, $p ) {
		$es    = 'es' === $p['lang'];
		$lang  = $es ? 'es' : 'en';
		$zoom  = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		$when  = SDSC_Lessons::when( $x['start'], $lang );
		$first = 1 === (int) $x['n'];
		list( $used ) = self::absences( $cid, $p['email'] );
		$subj  = 'day' === $kind
			? ( $es ? 'Mañana: clase de Zoom ' . $x['n'] . ' de ' . $total : 'Tomorrow: Zoom class ' . $x['n'] . ' of ' . $total )
			: ( $es ? 'En 1 hora: clase de Zoom ' . $x['n'] : 'In 1 hour: Zoom class ' . $x['n'] );
		$rules = $first
			? ( $es ? '<b>La primera clase es obligatoria</b> (regla de la MVA): si no asiste, la inscripción se cancela sin recuperación.' : '<b>The first class is mandatory</b> (MVA rule): missing it cancels the enrollment, with no make-up.' )
			: ( $es ? 'Ausencias usadas: <b>' . $used . ' de ' . self::ALLOWED . '</b>. Si no puede asistir, avísenos con el botón de abajo.' : 'Absences used: <b>' . $used . ' of ' . self::ALLOWED . '</b>. If you can’t attend, please tell us with the button below.' );
		$body = SDSC_Outreach::email_body( 'day' === $kind ? ( $es ? 'Su clase es mañana' : 'Your class is tomorrow' ) : ( $es ? 'Su clase empieza en una hora' : 'Your class starts in one hour' ), '', '', '', '💻' )
			. '<p style="margin:0 0 16px">' . ( $es ? 'Hola ' : 'Hi ' ) . esc_html( $p['first'] ) . ',</p>'
			. SDSC_Lessons::facts( array_filter( array(
				array( $es ? 'Clase' : 'Class', esc_html( ( $es ? 'Zoom ' : 'Zoom class ' ) . $x['n'] . ( $es ? ' de ' : ' of ' ) . $total . ' — ' . self::class_name( $cid ) ) ),
				array( $es ? 'Fecha y hora' : 'Date & time', '<b>' . esc_html( $when ) . '</b>' ),
				$zoom ? array( 'Zoom', '<a href="' . esc_url( $zoom ) . '" style="color:#00703c;font-weight:700">' . ( $es ? 'Entrar a la clase' : 'Join the class' ) . '</a>' ) : null,
			) ) )
			. '<p style="margin:0 0 16px">' . ( $es ? 'Cámara y micrófono encendidos, sentado en un escritorio, durante toda la clase.' : 'Camera and microphone on, sitting at a desk, for the whole class.' ) . '</p>'
			. SDSC_Lessons::box( $rules, $first ? 'warn' : 'ok' )
			. self::buttons_html( 'zoom', $cid, $x['date'], $p['email'], $lang )
			. self::cal_link_html( $p['email'], $lang );
		SDSC_Mailer::send( $p['email'], $subj . ' — ' . $when, $body, 'info' );
		if ( class_exists( 'SDSC_SMS' ) && $p['phone'] ) {
			$u = self::att_url( 'zoom', $cid, $x['date'], $p['email'] );
			SDSC_SMS::deliver( $p['phone'], ( $es ? $subj . ': ' . $when . '. Confirme o avise si no puede: ' : $subj . ': ' . $when . '. Confirm or tell us if you can’t: ' ) . $u . ' – Sam’s Driving School', 'zoom:' . $cid . ':' . $x['date'] . ':' . $kind . ':' . md5( $p['email'] ), trim( $p['first'] . ' ' . $p['last'] ), 'day' === $kind ? '💻 Zoom class tomorrow' : '💻 Zoom class in 1 hour', $x['start'] );
		}
	}

	/* ================================================================ staff: attendance sheet */

	public static function can() {
		return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'attendance' ) : current_user_can( 'manage_options' );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Class attendance', 'Class attendance', 'sdsc_access_attendance', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function page() {
		global $wpdb;
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		wp_enqueue_style( 'sdsc-outreach', SDSC_URL . 'assets/outreach.css', array(), SDSC_VERSION );
		wp_enqueue_style( 'sdsc-lessons', SDSC_URL . 'assets/lessons.css', array( 'sdsc-outreach' ), SDSC_VERSION );
		$classes = get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => array( 'publish', 'private', 'draft', 'pending' ), 'numberposts' => 60, 'orderby' => 'meta_value', 'meta_key' => '_sdsc_start', 'order' => 'DESC' ) ); // phpcs:ignore
		// Test and demo classes stay out of the sheet (the demo class is only for the "View as" preview accounts).
		$pv      = class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview();
		$classes = array_values( array_filter( $classes, function ( $p ) use ( $pv ) { return ( $pv || ! get_post_meta( $p->ID, '_sdsc_demo_class', true ) ) && ! preg_match( '/^(TEST-|ZZ)/i', (string) get_post_meta( $p->ID, '_sdsc_course_code', true ) ) && ! preg_match( '/payment test|\bTEST-\d/i', $p->post_title ); } ) );
		$cid     = isset( $_GET['class'] ) ? (int) $_GET['class'] : ( $classes ? $classes[0]->ID : 0 ); // phpcs:ignore
		?>
		<div class="wrap sdo sdl">
			<h1 class="sdo-title"><span class="sdo-title-ic" aria-hidden="true">🗓️</span> Class attendance</h1>
			<?php if ( isset( $_GET['msg'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<form method="get" class="sdo-filter sdl-inline"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<select name="class" onchange="this.form.submit()"><?php foreach ( $classes as $c ) : ?><option value="<?php echo (int) $c->ID; ?>"<?php selected( $cid, $c->ID ); ?>><?php echo esc_html( self::class_name( $c->ID, true ) . ' · ' . substr( (string) get_post_meta( $c->ID, '_sdsc_start', true ), 0, 10 ) ); ?></option><?php endforeach; ?></select>
				<noscript><button class="sdo-btn sdo-btn--ghost">Show</button></noscript>
			</form>
			<?php
			if ( ! $cid ) { echo '<p class="sdo-empty">No classes yet.</p></div>'; return; }
			$list   = self::sessions( $cid );
			$people = self::roster( $cid );
			if ( ! $list ) {
				echo '<p class="sdo-empty">This class has no timetable yet. <a href="' . esc_url( get_edit_post_link( $cid ) ) . '">Open the class</a> and fill in &ldquo;Timetable &amp; calendar&rdquo; (days, times, number of sessions).</p></div>';
				return;
			}
			$rows = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE kind = 'zoom' AND ref = %d", $cid ), ARRAY_A ) as $r ) { $rows[ $r['email'] . '|' . $r['sess'] ] = $r; } // phpcs:ignore
			$today = current_time( 'Y-m-d' );
			?>
			<p class="sdo-lead">✓ = said they will come · ✗ = told us they can&rsquo;t · pick <b>P</b> (present), <b>L</b> (late), <b>E</b> (left early) or <b>A</b> (absent) after each class — or let the instructor do it on the dashboard (Zoom attendance). Session 1 is mandatory; after it <?php echo (int) self::ALLOWED; ?> absences are allowed (policies 1 and 4).</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sdsc_att' ); ?><input type="hidden" name="action" value="sdsc_att_save"><input type="hidden" name="class" value="<?php echo (int) $cid; ?>">
				<div style="overflow-x:auto">
				<table class="widefat striped sdl-att">
					<thead><tr><th>Student</th><?php foreach ( $list as $x ) : ?><th title="<?php echo esc_attr( $x['date'] ); ?>"><?php echo (int) $x['n']; ?><br><span class="sdo-muted"><?php echo esc_html( wp_date( 'M j', SDSC_Lessons::ts( $x['start'] ) ) ); ?></span></th><?php endforeach; ?><th>Absences</th></tr></thead>
					<tbody>
					<?php foreach ( $people as $p ) : list( $used, $miss1 ) = self::absences( $cid, $p['email'] ); ?>
						<tr>
							<td><b><?php echo esc_html( trim( $p['first'] . ' ' . $p['last'] ) ); ?></b><br><span class="sdo-muted"><?php echo esc_html( $p['email'] ); ?></span></td>
							<?php foreach ( $list as $x ) : $r = $rows[ $p['email'] . '|' . $x['date'] ] ?? null; $name = 'm[' . md5( $p['email'] ) . '][' . $x['date'] . ']'; ?>
								<td class="sdl-cell<?php echo $r && 'absent' === $r['actual'] ? ' is-abs' : ''; ?>">
									<?php echo $r && 'yes' === $r['rsvp'] ? '<span title="Confirmed">✓</span>' : ( $r && 'no' === $r['rsvp'] ? '<span title="' . esc_attr( 'Told us: ' . $r['rsvp_at'] . ( $r['note'] ? ' — ' . $r['note'] : '' ) ) . '" style="color:#b32d2e">✗</span>' : '' ); ?>
									<?php if ( $x['date'] <= $today ) : ?>
										<input type="hidden" name="e[<?php echo esc_attr( md5( $p['email'] ) ); ?>]" value="<?php echo esc_attr( $p['email'] ); ?>">
										<select name="<?php echo esc_attr( $name ); ?>" aria-label="<?php echo esc_attr( $p['first'] . ' session ' . $x['n'] ); ?>"><option value="">—</option><option value="present"<?php selected( $r['actual'] ?? '', 'present' ); ?>>P</option><option value="late"<?php selected( $r['actual'] ?? '', 'late' ); ?>>L</option><option value="left"<?php selected( $r['actual'] ?? '', 'left' ); ?>>E</option><option value="absent"<?php selected( $r['actual'] ?? '', 'absent' ); ?>>A</option></select>
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
							<td><?php echo $miss1 ? '<b style="color:#b32d2e">Missed session 1</b><br>' : ''; ?><b style="color:<?php echo $used > self::ALLOWED ? '#b32d2e' : ( $used === self::ALLOWED ? '#8a4b00' : '#1d2327' ); ?>"><?php echo (int) $used; ?> / <?php echo (int) self::ALLOWED; ?></b></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p><button class="sdo-btn">Save attendance</button></p>
			</form>
		</div>
		<?php
	}

	public static function h_save() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_att' );
		$cid    = (int) ( $_POST['class'] ?? 0 );
		$emails = array_map( 'sanitize_email', (array) wp_unslash( $_POST['e'] ?? array() ) );
		$marks  = (array) wp_unslash( $_POST['m'] ?? array() ); // phpcs:ignore -- values whitelisted below
		$valid  = wp_list_pluck( self::sessions( $cid ), 'date' );
		foreach ( $marks as $h => $days ) {
			$email = $emails[ $h ] ?? '';
			if ( ! $email ) { continue; }
			foreach ( (array) $days as $date => $v ) {
				if ( ! in_array( $date, $valid, true ) || ! in_array( $v, array( '', 'present', 'late', 'left', 'absent' ), true ) ) { continue; }
				$r = self::row( 'zoom', $cid, $date, $email );
				if ( ! $r && '' === $v ) { continue; }
				if ( $r && $r['actual'] === $v ) { continue; }
				self::put( 'zoom', $cid, $date, $email, array( 'actual' => $v, 'actual_by' => get_current_user_id(), 'actual_at' => current_time( 'mysql' ) ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&class=' . $cid . '&msg=' . rawurlencode( 'Attendance saved.' ) ) );
		exit;
	}

	/** Student dashboard: calendar buttons and absences per class. */
	public static function student_html( $email, $lang ) {
		$es = 'es' === $lang;
		$h  = '<p><a class="sdl-pay" href="' . esc_url( self::cal_page( $email ) ) . '">📅 ' . ( $es ? 'Agregar todas mis clases a mi calendario' : 'Add all my classes to my calendar' ) . '</a></p>';
		foreach ( self::classes_of( $email ) as $cid ) {
			$list = self::sessions( $cid );
			if ( ! $list ) { continue; }
			list( $used, $miss1 ) = self::absences( $cid, $email );
			$next = null;
			foreach ( $list as $x ) { if ( SDSC_Lessons::ts( $x['end'] ) > time() ) { $next = $x; break; } }
			$h .= '<article class="sdl-h"><div class="sdl-h-top"><div><b>' . esc_html( self::class_name( $cid ) ) . '</b><br><span class="sdl-m">'
				. ( $next ? esc_html( ( $es ? 'Próxima clase: ' : 'Next class: ' ) . $next['n'] . ' — ' . SDSC_Lessons::when( $next['start'], $lang ) ) : ( $es ? 'Todas las clases terminaron.' : 'All classes are done.' ) )
				. '</span></div><span class="sdl-pill' . ( $used > self::ALLOWED || $miss1 ? ' sdl-pill--due' : '' ) . '">' . esc_html( ( $es ? 'Ausencias ' : 'Absences ' ) . $used . '/' . self::ALLOWED ) . '</span></div>';
			if ( class_exists( 'SDSC_ZoomRoll' ) ) { $h .= SDSC_ZoomRoll::student_strip( $cid, $email, $lang ); }
			if ( $next ) {
				$u   = self::att_url( 'zoom', $cid, $next['date'], $email );
				$h  .= '<p class="sdl-note"><a href="' . esc_url( add_query_arg( 'a', 'yes', $u ) ) . '">' . ( $es ? '✓ Confirmar asistencia' : '✓ Confirm I’ll be there' ) . '</a> · <a href="' . esc_url( add_query_arg( 'a', 'no', $u ) ) . '">' . ( $es ? 'No puedo asistir' : 'I can’t make it' ) . '</a></p>';
			}
			$h .= '</article>';
		}
		return '<div class="sdl-hist">' . $h . '</div>';
	}
}
