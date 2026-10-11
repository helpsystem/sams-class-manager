<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Current weather in Rockville, MD for the homepage scene (rain, snow, fog, clouds, storms).
 *
 * The server asks Open-Meteo (free, no key) at most every 20 minutes and keeps the answer in a transient,
 * so visitors' browsers never contact a weather service and nothing about them is shared.
 * Public endpoint: /wp-json/sdsc/v1/weather
 */
class SDSC_Weather {
	const LAT = 39.0840;
	const LON = -77.1528;
	const KEY = 'sdsc_weather_now';

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'sdsc/v1', '/weather', array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$r = rest_ensure_response( SDSC_Weather::now() );
					$r->header( 'Cache-Control', 'public, max-age=600' );
					return $r;
				},
			) );
		} );
	}

	/** kind: clear | clouds | fog | rain | snow | storm; cover 0..1; amount 0..1; wind km/h; temp °C. */
	public static function now() {
		$c = get_transient( self::KEY );
		if ( is_array( $c ) ) { return $c; }
		$url = add_query_arg( array(
			'latitude'  => self::LAT,
			'longitude' => self::LON,
			'current'   => 'weather_code,cloud_cover,precipitation,rain,showers,snowfall,wind_speed_10m,temperature_2m',
			'timezone'  => 'America/New_York',
		), 'https://api.open-meteo.com/v1/forecast' );
		$res = wp_remote_get( $url, array( 'timeout' => 6, 'user-agent' => 'SamsDrivingSchool/1.0 (+' . home_url( '/' ) . ')' ) );
		$out = array( 'kind' => 'clear', 'cover' => 0.25, 'amount' => 0, 'wind' => 8, 'temp' => 15, 'ok' => 0, 't' => time() );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			$n = $j['current'] ?? null;
			if ( is_array( $n ) ) {
				$code   = (int) ( $n['weather_code'] ?? 0 );
				$precip = (float) ( $n['precipitation'] ?? 0 );
				$kind   = 'clear';
				if ( $code >= 95 ) { $kind = 'storm'; }
				elseif ( in_array( $code, array( 71, 73, 75, 77, 85, 86 ), true ) ) { $kind = 'snow'; }
				elseif ( ( $code >= 51 && $code <= 67 ) || ( $code >= 80 && $code <= 82 ) ) { $kind = 'rain'; }
				elseif ( 45 === $code || 48 === $code ) { $kind = 'fog'; }
				elseif ( $code >= 2 ) { $kind = 'clouds'; }
				$amount = 'snow' === $kind ? min( 1, (float) ( $n['snowfall'] ?? 0 ) / 1.5 + 0.35 ) : min( 1, $precip / 4 + ( 'clear' === $kind || 'clouds' === $kind || 'fog' === $kind ? 0 : 0.3 ) );
				$out = array(
					'kind'   => $kind,
					'cover'  => round( min( 100, max( 0, (float) ( $n['cloud_cover'] ?? 0 ) ) ) / 100, 2 ),
					'amount' => round( $amount, 2 ),
					'wind'   => round( (float) ( $n['wind_speed_10m'] ?? 0 ), 1 ),
					'temp'   => round( (float) ( $n['temperature_2m'] ?? 15 ), 1 ),
					'code'   => $code,
					'ok'     => 1,
					't'      => time(),
				);
			}
		}
		set_transient( self::KEY, $out, $out['ok'] ? 20 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $out;
	}
}
