<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * MVA headlines, updated automatically once a day.
 *
 * Legal care: the MVA site is "© State of Maryland, all rights reserved" and Maryland.gov's
 * linking policy forbids framing their pages, presenting their content as ours, or implying
 * the State endorses us. So we store and show only the headline, the date and a link to the
 * original page on mva.maryland.gov — never their article text, images or logos — with a
 * clear "not affiliated" note. Staff can turn a headline into a draft post and write their
 * own short explanation in their own words.
 *
 *   Shortcode: [sdsc_mva_news count="5" title="Latest MVA updates"]
 */
class SDSC_MVA_News {

	const OPT    = 'sdsc_mva_news';
	const SOURCE = 'https://mva.maryland.gov/news';
	const HOOK   = 'sdsc_mva_news_refresh';

	public static function init() {
		add_shortcode( 'sdsc_mva_news', array( __CLASS__, 'shortcode' ) );
		add_action( self::HOOK, array( __CLASS__, 'refresh' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_post_sdsc_mva_refresh', array( __CLASS__, 'admin_refresh' ) );
		add_action( 'admin_post_sdsc_mva_draft', array( __CLASS__, 'admin_draft' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 300, 'daily', self::HOOK ); }
	}

	/** Words that make a headline useful to new drivers and parents. */
	protected static function relevant( $title ) {
		if ( preg_match( '/^\s*media advisory|killed|fatalit/i', $title ) ) { return false; }
		return (bool) preg_match( '/driver|license|licen[cs]e|learner|permit|curriculum|test|teen|REAL ID|close|closure|holiday|office|hours|online|safety|seat ?belt|school bus|distracted|impaired|speed|work zone|pedestrian|crash|VEIP|registration/i', $title );
	}

	public static function refresh() {
		$r = wp_remote_get( self::SOURCE, array( 'timeout' => 20, 'user-agent' => 'SamsDrivingSchool-Headlines/1.0 (+' . home_url( '/' ) . ')' ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) { return false; }
		$html = wp_remote_retrieve_body( $r );
		if ( ! $html || ! class_exists( 'DOMDocument' ) ) { return false; }
		$doc = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors(); libxml_use_internal_errors( $old );
		$xp    = new DOMXPath( $doc );
		$items = array();
		foreach ( $xp->query( "//a[contains(concat(' ', normalize-space(@class), ' '), ' maryland-listing-item__title ')]" ) as $a ) {
			$href  = $a->getAttribute( 'href' );
			$title = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) );
			if ( ! $title || ! preg_match( '#^/news/[\w\-]+$#', $href ) ) { continue; }
			$date = '';
			// The date lives in the same listing item.
			$item = $a;
			for ( $i = 0; $i < 6 && $item && ! $date; $i++ ) {
				$item = $item->parentNode;
				if ( $item instanceof DOMElement ) {
					$t = $xp->query( './/time[@datetime]', $item );
					if ( $t && $t->length ) { $date = substr( $t->item( 0 )->getAttribute( 'datetime' ), 0, 10 ); }
				}
			}
			$items[] = array( 'title' => wp_strip_all_tags( $title ), 'url' => 'https://mva.maryland.gov' . $href, 'date' => $date, 'relevant' => self::relevant( $title ) );
			if ( count( $items ) >= 20 ) { break; }
		}
		if ( ! $items ) { return false; }
		update_option( self::OPT, array( 'items' => $items, 'fetched' => time() ), false );
		return count( $items );
	}

	protected static function items( $only_relevant = true ) {
		$d = get_option( self::OPT );
		$a = is_array( $d ) && ! empty( $d['items'] ) ? $d['items'] : array();
		return $only_relevant ? array_values( array_filter( $a, function ( $i ) { return ! empty( $i['relevant'] ); } ) ) : $a;
	}

	public static function shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'count' => 5, 'title' => 'Latest MVA updates for Maryland drivers' ), $atts );
		$items = array_slice( self::items(), 0, max( 1, (int) $atts['count'] ) );
		if ( ! $items ) { return ''; }
		if ( class_exists( 'SDSC_Signs' ) ) { SDSC_Signs::enqueue(); }
		ob_start();
		?>
		<section class="smn" aria-labelledby="smn-h">
			<div class="smn-head">
				<span class="smn-sign" aria-hidden="true">i</span>
				<div><h2 id="smn-h"><?php echo esc_html( $atts['title'] ); ?></h2><p>Headlines from the Maryland MVA&rsquo;s public news page, checked daily. Tap a headline to read it on mva.maryland.gov.</p></div>
			</div>
			<ul class="smn-list">
				<?php foreach ( $items as $i ) : ?>
					<li>
						<?php if ( $i['date'] ) : ?><time datetime="<?php echo esc_attr( $i['date'] ); ?>"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $i['date'] ) ) ); ?></time><?php endif; ?>
						<a href="<?php echo esc_url( $i['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $i['title'] ); ?><span class="smn-ext" aria-hidden="true"> &#8599;</span><span class="screen-reader-text"> (opens mva.maryland.gov in a new tab)</span></a>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="smn-note">Sam&rsquo;s Driving School is a private driving school and is not affiliated with or endorsed by the Maryland Motor Vehicle Administration. Always confirm details on the official MVA website.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ---------------- Admin: refresh + "write a post about this" ---------------- */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'MVA headlines', 'MVA headlines', 'sdsc_access_mva', 'sdsc-mva', array( __CLASS__, 'page' ) );
	}

	public static function admin_refresh() {
		if ( ! SDSC_Access::can( 'mva' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_mva' );
		$n = self::refresh();
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-mva&refreshed=' . (int) $n ) );
		exit;
	}

	public static function admin_draft() {
		if ( ! SDSC_Access::can( 'mva' ) || ! current_user_can( 'edit_posts' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_mva' );
		$i = (int) ( $_GET['i'] ?? -1 );
		$all = self::items( false );
		if ( empty( $all[ $i ] ) ) { wp_die( 'Headline not found.' ); }
		$h = $all[ $i ];
		$content = "<!-- wp:paragraph --><p><em>Write a short explanation in your own words: what this means for students and parents, and what they should do next. Do not copy text from the MVA page.</em></p><!-- /wp:paragraph -->\n"
			. '<!-- wp:paragraph --><p>Source: <a href="' . esc_url( $h['url'] ) . '" target="_blank" rel="noopener">Maryland MVA — ' . esc_html( $h['title'] ) . '</a>' . ( $h['date'] ? ' (' . esc_html( date_i18n( 'F j, Y', strtotime( $h['date'] ) ) ) . ')' : '' ) . '</p><!-- /wp:paragraph -->'
			. "\n<!-- wp:paragraph --><p><small>Sam&rsquo;s Driving School is not affiliated with or endorsed by the Maryland MVA.</small></p><!-- /wp:paragraph -->";
		$cat = get_term_by( 'slug', 'maryland-laws', 'category' );
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'MVA update: ' . $h['title'], 'post_content' => $content, 'post_category' => $cat ? array( (int) $cat->term_id ) : array() ) );
		wp_safe_redirect( $id ? get_edit_post_link( $id, 'url' ) : admin_url( 'admin.php?page=sdsc-mva' ) );
		exit;
	}

	public static function page() {
		$d = get_option( self::OPT );
		$all = self::items( false );
		$nonce = wp_create_nonce( 'sdsc_mva' );
		?>
		<div class="wrap">
			<h1>MVA headlines</h1>
			<p class="sdsc-lead" id="sdsc-mva-lead">Checked automatically once a day from <a href="<?php echo esc_url( self::SOURCE ); ?>" target="_blank" rel="noopener">mva.maryland.gov/news</a>. The site shows only the headline, date and a link to the MVA page (the MVA text is copyrighted by the State of Maryland). Use <strong>Write a post</strong> to start a draft where you explain the update in your own words.
			<?php if ( current_user_can( 'manage_options' ) ) : ?><span class="description">Administrator note: the list is the <code>[sdsc_mva_news]</code> shortcode.</span><?php endif; ?></p>
			<?php if ( isset( $_GET['refreshed'] ) ) : ?><div class="notice notice-info"><p><?php echo $_GET['refreshed'] ? esc_html( (int) $_GET['refreshed'] . ' headlines fetched.' ) : 'Could not read the MVA news page right now.'; ?></p></div><?php endif; ?>
			<p id="sdsc-mva-check"><a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=sdsc_mva_refresh&_wpnonce=' . $nonce ) ); ?>">Check now</a>
			<?php if ( ! empty( $d['fetched'] ) ) : ?> <span style="color:#646970">Last checked <?php echo esc_html( human_time_diff( $d['fetched'] ) ); ?> ago.</span><?php endif; ?></p>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th style="width:110px">Date</th><th>Headline</th><th style="width:110px">Shown on site</th><th style="width:130px"></th></tr></thead>
				<tbody>
				<?php foreach ( $all as $k => $i ) : ?>
					<tr><td><?php echo esc_html( $i['date'] ); ?></td><td><a href="<?php echo esc_url( $i['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $i['title'] ); ?></a></td><td><?php echo $i['relevant'] ? 'Yes' : '—'; ?></td>
					<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin-post.php?action=sdsc_mva_draft&i=' . $k . '&_wpnonce=' . $nonce ) ); ?>">Write a post</a></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $all ) : ?><tr><td colspan="4">No headlines yet — press “Check now”.</td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
