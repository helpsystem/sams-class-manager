<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * "Why Choose Us?" — redraws the theme's ruzbahani_why_us widget.
 * Text comes from the theme template (so edits there still show up); proof tiles use only
 * facts the site already states, and the Google rating is read live from the reviews plugin.
 */
class SDSC_WhyUs {

	public static function fix_name( $t ) {
		return preg_replace( '/\bSams?\s+Driving\s+School\b/i', "Sam\u{2019}s Driving School", $t );
	}

	/** Live Google rating from the Trustindex reviews plugin, or null. */
	public static function google() {
		$d = get_option( 'trustindex-google-page-details' );
		if ( ! is_array( $d ) ) { return null; }
		$n = 0; $s = 0;
		foreach ( $d as $k => $v ) {
			if ( ! is_scalar( $v ) ) { continue; }
			if ( preg_match( '/rating_number|review_count|reviews_count/i', $k ) ) { $n = (int) $v; }
			if ( preg_match( '/rating_score|^rating$|score/i', $k ) ) { $s = (float) $v; }
		}
		if ( $n < 10 || $s <= 0 ) { return null; }
		return array( 'score' => number_format( floor( $s * 10 ) / 10, 1 ), 'count' => number_format( floor( $n / 10 ) * 10 ) . '+' );
	}

	protected static function icon( $k ) {
		$p = array(
			'teach' => '<circle cx="24" cy="15" r="7"/><path d="M11 40c1-8 6-13 13-13s12 5 13 13z"/><path d="M33 22l3 2 5-6" fill="none" stroke="#ffc20e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>',
			'clock' => '<circle cx="24" cy="24" r="15" fill="none" stroke="currentColor" stroke-width="4"/><path d="M24 15v10l7 5" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>',
			'pin'   => '<path d="M24 6a12 12 0 0 0-12 12c0 9 12 22 12 22s12-13 12-22A12 12 0 0 0 24 6zm0 16.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z"/>',
			'car'   => '<path d="M8 30c0-3 2-5 5-5l5-1 5-6c1-1 3-2 5-2h4c2 0 3 1 4 2l4 6 2 .5c2 .5 3 2 3 4v4c0 1-1 2-2 2H10c-1 0-2-1-2-2z"/><circle cx="16" cy="35" r="4" fill="#fff" stroke="currentColor" stroke-width="3"/><circle cx="36" cy="35" r="4" fill="#fff" stroke="currentColor" stroke-width="3"/>',
		);
		return '<svg viewBox="0 0 48 48" aria-hidden="true" fill="currentColor">' . $p[ $k ] . '</svg>';
	}

	public static function render( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $html ) ) { return ''; }
		$doc = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors(); libxml_use_internal_errors( $old );
		$xp = new DOMXPath( $doc );
		$txt = function ( $n ) { return $n ? trim( preg_replace( '/\s+/u', ' ', $n->textContent ) ) : ''; };
		$one = function ( $q, $ctx = null ) use ( $xp ) { $r = $xp->query( $q, $ctx ); return ( $r && $r->length ) ? $r->item( 0 ) : null; };

		$title = $txt( $one( "//*[contains(@class,'heading-top')]//h2" ) );
		$intro = $txt( $one( "//*[contains(@class,'heading-top')]//p" ) );
		$features = array();
		foreach ( $xp->query( "//*[contains(@class,'media-body')]" ) as $mb ) {
			$features[] = array( $txt( $one( './/h6', $mb ) ), $txt( $one( './/p', $mb ) ) );
		}
		if ( count( $features ) < 2 ) { return ''; }
		$story_h = $txt( $one( "//*[contains(@class,'midcontent')]//h4" ) );
		$story   = array();
		foreach ( $xp->query( "//*[contains(@class,'midcontent')]//p" ) as $p ) { $story[] = $txt( $p ); }
		$imgs = array();
		foreach ( $xp->query( '//img[@src]' ) as $im ) {
			$src = $im->getAttribute( 'src' );
			if ( preg_match( '/\.(jpe?g|webp)$/i', $src ) ) { $imgs[] = $src; }
		}
		$icons = array( 'teach', 'clock', 'pin', 'car' );
		$g     = self::google();
		$tel   = 'tel:+13017263030';
		if ( class_exists( 'SDSC_Signs' ) ) { SDSC_Signs::enqueue(); }
		ob_start();
		?>
		<section class="swu" aria-labelledby="swu-h">
			<div class="swu-wrap">
				<div class="swu-head">
					<p class="swu-kicker">Why Sam&rsquo;s</p>
					<h2 id="swu-h"><?php echo esc_html( $title ? $title : 'Why Choose Us?' ); ?></h2>
					<?php if ( $intro ) : ?><p class="swu-intro"><?php echo esc_html( self::fix_name( $intro ) ); ?></p><?php endif; ?>
				</div>
				<ul class="swu-proof">
					<?php if ( $g ) : ?>
						<li><span class="swu-big"><?php echo esc_html( $g['score'] ); ?><span class="swu-star" aria-hidden="true">&#9733;</span></span><span>Google rating &middot; <?php echo esc_html( $g['count'] ); ?> reviews</span></li>
					<?php endif; ?>
					<li><span class="swu-big">Best of 2025</span><span>Rockville &middot; BusinessRate award</span></li>
					<li><span class="swu-big">Women-owned</span><span>&amp; operated in Rockville</span></li>
					<li><span class="swu-big">Male &amp; female instructors</span><span>English, Spanish &amp; Farsi (Persian)</span></li>
				</ul>
				<div class="swu-grid">
					<ul class="swu-features">
						<?php foreach ( $features as $i => $f ) : ?>
							<li class="swu-card" style="--i:<?php echo (int) $i; ?>">
								<span class="swu-ic swu-ic--<?php echo (int) $i % 4; ?>"><?php echo self::icon( $icons[ $i % 4 ] ); // phpcs:ignore -- static svg ?></span>
								<div><h3><?php echo esc_html( $f[0] ); ?></h3><p><?php echo esc_html( self::fix_name( $f[1] ) ); ?></p></div>
							</li>
						<?php endforeach; ?>
					</ul>
					<div class="swu-story">
						<div class="swu-photos">
							<?php if ( ! empty( $imgs[0] ) ) : ?><img class="swu-ph swu-ph--a" src="<?php echo esc_url( $imgs[0] ); ?>" alt="Sam&rsquo;s Driving School training car" loading="lazy" decoding="async" /><?php endif; ?>
							<?php if ( ! empty( $imgs[1] ) ) : ?><img class="swu-ph swu-ph--b" src="<?php echo esc_url( $imgs[1] ); ?>" alt="Sam&rsquo;s Driving School car in Rockville, Maryland" loading="lazy" decoding="async" /><?php endif; ?>
							<span class="swu-tag">Rockville, MD</span>
						</div>
						<div class="swu-story-t">
							<h3><?php echo esc_html( $story_h ? $story_h : 'Learn Theoretically. Apply Practically.' ); ?></h3>
							<?php foreach ( array_slice( $story, 0, 2 ) as $p ) : ?><p><?php echo esc_html( self::fix_name( $p ) ); ?></p><?php endforeach; ?>
							<div class="swu-ctas">
								<a class="sdsc3d-cta" href="<?php echo esc_url( home_url( '/services/' ) ); ?>">See classes &amp; prices</a>
								<a class="sdsc3d-cta sdsc3d-cta--ghost" href="<?php echo esc_attr( $tel ); ?>">Call (301) 726-3030</a>
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}
}
