<?php
/**
 * Lead source tracking: UTM parameters, ad click IDs (gclid, fbclid, msclkid…),
 * landing page, referrer and a computed channel ("Google Ads", "Organic search"…).
 *
 * The frontend script (assets/attribution.js) captures the data in the visitor's
 * browser and posts it with the form in a hidden field; nothing is stored on the
 * server outside the submission itself.
 *
 * @package SheetSync_CF7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SS_CF7_Attribution
 */
final class SS_CF7_Attribution {

	/** Option holding the tracking settings. */
	public const OPTION = 'ss_cf7_tracking';

	/** Hidden field added to the CF7 forms (leading "_" keeps it out of CF7 mail/posted data). */
	public const FIELD = '_ss_cf7_attribution';

	/** URL parameters captured by the frontend script. */
	public const PARAMS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid' );

	/** Values exposed as metadata columns and mail-tags (prefixed "_ss_"). */
	public const KEYS = array( 'channel', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'landing_page', 'referrer' );

	/** Channels, in the order shown in the settings. */
	public const CHANNELS = array( 'google_ads', 'meta_ads', 'microsoft_ads', 'tiktok_ads', 'other_paid', 'organic_search', 'organic_social', 'email', 'referral', 'campaign', 'direct' );

	/** "Remember for" choices in days (0 = only the landing page, no browser storage). */
	public const DAYS_CHOICES = array( 0, 7, 30, 90 );

	/** utm_medium values meaning paid traffic. */
	private const PAID_MEDIUMS = array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paidsocial', 'paid_social', 'paid-social', 'display', 'cpm', 'cpv', 'banner', 'retargeting', 'remarketing', 'ads', 'ad' );

	/** Referrer hosts of search engines. */
	private const SEARCH_HOSTS = '/(^|\.)(google|bing|yahoo|duckduckgo|ecosia|baidu|yandex|qwant|startpage|search\.brave|virgilio|libero)\./';

	/** Referrer hosts / utm_source values of social networks. */
	private const SOCIAL_HOSTS = '/(^|\.)(facebook|fb|instagram|linkedin|lnkd|twitter|t|x|youtube|tiktok|pinterest|reddit|whatsapp|telegram|threads)\.(com|co|in|me|net|org)$|^(facebook|fb|ig|instagram|linkedin|twitter|x|youtube|tiktok|pinterest|reddit|whatsapp|telegram|threads|social)$/';

	/**
	 * Attribution of the current request (memoized).
	 *
	 * @var array|null
	 */
	private static ?array $current = null;

	/**
	 * Registers hooks only.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'wpcf7_special_mail_tags', array( $this, 'mail_tag' ), 10, 3 );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Options
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Tracking settings.
	 *
	 * @return array{enabled: bool, days: int, model: string, consent: bool, labels: array<string, string>}
	 */
	public static function options(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return self::sanitize_options(
			array_merge(
				array(
					'enabled' => false,
					'days'    => 90,
					'model'   => 'last',
					'consent' => true,
					'labels'  => array(),
				),
				$saved
			)
		);
	}

	/**
	 * Sanitizes the tracking settings (a missing checkbox means "off").
	 *
	 * @param array $raw Raw settings.
	 */
	public static function sanitize_options( array $raw ): array {
		$days   = isset( $raw['days'] ) ? absint( $raw['days'] ) : 90;
		$labels = array();

		if ( isset( $raw['labels'] ) && is_array( $raw['labels'] ) ) {
			foreach ( self::CHANNELS as $channel ) {
				$label = $raw['labels'][ $channel ] ?? '';
				$label = is_scalar( $label ) ? mb_substr( sanitize_text_field( (string) $label ), 0, 100 ) : '';
				if ( '' !== $label ) {
					$labels[ $channel ] = $label;
				}
			}
		}

		return array(
			'enabled' => ! empty( $raw['enabled'] ),
			'days'    => in_array( $days, self::DAYS_CHOICES, true ) ? $days : 90,
			'model'   => isset( $raw['model'] ) && 'first' === $raw['model'] ? 'first' : 'last',
			'consent' => ! empty( $raw['consent'] ),
			'labels'  => $labels,
		);
	}

	/**
	 * Default (translated) channel names.
	 *
	 * @return array<string, string>
	 */
	public static function default_labels(): array {
		return array(
			'google_ads'     => __( 'Google Ads', 'sheetsync-cf7' ),
			'meta_ads'       => __( 'Meta Ads', 'sheetsync-cf7' ),
			'microsoft_ads'  => __( 'Microsoft Ads', 'sheetsync-cf7' ),
			'tiktok_ads'     => __( 'TikTok Ads', 'sheetsync-cf7' ),
			'other_paid'     => __( 'Other paid ads', 'sheetsync-cf7' ),
			'organic_search' => __( 'Organic search', 'sheetsync-cf7' ),
			'organic_social' => __( 'Social', 'sheetsync-cf7' ),
			'email'          => __( 'Email', 'sheetsync-cf7' ),
			'referral'       => __( 'Other website', 'sheetsync-cf7' ),
			'campaign'       => __( 'Other campaign', 'sheetsync-cf7' ),
			'direct'         => __( 'Direct', 'sheetsync-cf7' ),
		);
	}

	/**
	 * Name written for a channel: the custom one or the default.
	 *
	 * @param string     $channel Channel key.
	 * @param array|null $options Settings (default: the saved ones).
	 */
	public static function channel_label( string $channel, ?array $options = null ): string {
		$options = $options ?? self::options();

		return $options['labels'][ $channel ] ?? ( self::default_labels()[ $channel ] ?? '' );
	}

	/**
	 * Whether a consent plugin compatible with the WP Consent API is active.
	 */
	public static function consent_api_active(): bool {
		return function_exists( 'wp_has_consent' );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Frontend
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Loads the capture script on every public page (landing pages often have no form).
	 */
	public function enqueue(): void {
		$options = self::options();
		if ( ! $options['enabled'] ) {
			return;
		}

		wp_enqueue_script(
			'ss-cf7-attribution',
			SS_CF7_URL . 'assets/attribution.js',
			array(),
			SS_CF7_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'ss-cf7-attribution',
			'ssCf7Attr',
			array(
				'field'   => self::FIELD,
				'params'  => self::PARAMS,
				'days'    => $options['days'],
				'model'   => $options['model'],
				'consent' => $options['consent'],
			)
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Server side
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Attribution posted with the current submission.
	 *
	 * @return array<string, string> KEYS => value ('' when unknown; all '' when tracking is off or nothing was posted).
	 */
	public static function current(): array {
		if ( null !== self::$current ) {
			return self::$current;
		}

		$raw  = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public form submission (CF7 validates it); every value is sanitized in parse().
		$data = '' !== $raw && strlen( $raw ) < 10000 ? json_decode( $raw, true ) : null;

		self::$current = self::options()['enabled'] && is_array( $data ) ? self::parse( $data ) : array_fill_keys( self::KEYS, '' );

		return self::$current;
	}

	/**
	 * Forgets the memoized attribution (tests, multiple submissions in one request).
	 */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * Validates the posted data and computes the channel.
	 *
	 * @param array $data Decoded JSON: {p: {param: value}, landing: url, ref: url}.
	 * @return array<string, string>
	 */
	public static function parse( array $data ): array {
		$params = array();
		$posted = isset( $data['p'] ) && is_array( $data['p'] ) ? $data['p'] : array();

		foreach ( self::PARAMS as $key ) {
			$value          = $posted[ $key ] ?? '';
			$params[ $key ] = is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 300 ) : '';
		}

		$out = array_fill_keys( self::KEYS, '' );

		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'msclkid' ) as $key ) {
			$out[ $key ] = $params[ $key ];
		}

		// Google: gclid, or gbraid/wbraid for iOS app/web campaigns.
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $key ) {
			if ( '' !== $params[ $key ] ) {
				$out['gclid'] = $params[ $key ];
				break;
			}
		}

		$out['landing_page'] = self::clean_url( $data['landing'] ?? '' );
		$out['referrer']     = self::clean_url( $data['ref'] ?? '' );

		$channel = self::detect_channel( $params, $out['referrer'] );

		/**
		 * Filters the detected channel key (see SS_CF7_Attribution::CHANNELS).
		 *
		 * @param string $channel Channel key.
		 * @param array  $params  Captured URL parameters.
		 * @param string $referrer External referrer (origin + path).
		 */
		$channel        = (string) apply_filters( 'ss_cf7_channel', $channel, $params, $out['referrer'] );
		$out['channel'] = self::channel_label( $channel );

		return $out;
	}

	/**
	 * Classifies the visit.
	 *
	 * @param array  $params   Captured URL parameters (all PARAMS keys).
	 * @param string $referrer External referrer URL ('' when none).
	 * @return string Channel key.
	 */
	public static function detect_channel( array $params, string $referrer ): string {
		$source = strtolower( trim( (string) ( $params['utm_source'] ?? '' ) ) );
		$medium = strtolower( trim( (string) ( $params['utm_medium'] ?? '' ) ) );

		if ( '' !== ( $params['gclid'] ?? '' ) || '' !== ( $params['gbraid'] ?? '' ) || '' !== ( $params['wbraid'] ?? '' ) ) {
			return 'google_ads';
		}
		if ( '' !== ( $params['msclkid'] ?? '' ) ) {
			return 'microsoft_ads';
		}
		if ( '' !== ( $params['ttclid'] ?? '' ) ) {
			return 'tiktok_ads';
		}

		$paid = in_array( $medium, self::PAID_MEDIUMS, true ) || str_contains( $medium, 'paid' );

		if ( $paid ) {
			if ( preg_match( '/google|adwords|youtube/', $source ) ) {
				return 'google_ads';
			}
			if ( preg_match( '/facebook|^fb$|instagram|^ig$|meta/', $source ) ) {
				return 'meta_ads';
			}
			if ( preg_match( '/bing|microsoft/', $source ) ) {
				return 'microsoft_ads';
			}
			if ( str_contains( $source, 'tiktok' ) ) {
				return 'tiktok_ads';
			}
			return 'other_paid';
		}

		if ( in_array( $medium, array( 'email', 'e-mail', 'mail', 'newsletter' ), true ) ) {
			return 'email';
		}

		// fbclid is added to every outbound Facebook/Instagram link, ads and organic posts alike.
		if ( in_array( $medium, array( 'social', 'social-network', 'social-media', 'sm', 'organic_social', 'social_organic' ), true ) || preg_match( self::SOCIAL_HOSTS, $source ) || '' !== ( $params['fbclid'] ?? '' ) ) {
			return 'organic_social';
		}

		if ( 'organic' === $medium ) {
			return 'organic_search';
		}

		if ( '' !== $source ) {
			return 'campaign';
		}

		if ( '' !== $referrer ) {
			$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
			$host = preg_replace( '/^www\./', '', $host );

			if ( preg_match( self::SEARCH_HOSTS, $host ) ) {
				return 'organic_search';
			}
			if ( preg_match( self::SOCIAL_HOSTS, $host ) ) {
				return 'organic_social';
			}
			return 'referral';
		}

		return 'direct';
	}

	/**
	 * CF7 special mail-tags: [_ss_channel], [_ss_utm_source], [_ss_gclid]…
	 * Usable in the CF7 emails and in the custom texts of the column mapping.
	 *
	 * @param string|null $output Current output.
	 * @param string      $name   Tag name.
	 * @param bool        $html   Whether the output is HTML.
	 * @return string|null
	 */
	public function mail_tag( $output, $name, $html ) {
		$name = (string) $name;
		if ( ! str_starts_with( $name, '_ss_' ) ) {
			return $output;
		}

		$key = substr( $name, 4 );
		if ( ! in_array( $key, self::KEYS, true ) ) {
			return $output;
		}

		$value = self::current()[ $key ];

		return $html ? esc_html( $value ) : $value;
	}

	/**
	 * Keeps only origin + path of an http(s) URL.
	 *
	 * @param mixed $url Raw URL.
	 */
	private static function clean_url( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( mb_substr( $url, 0, 1000 ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return '';
		}

		return mb_substr( esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . ( $parts['path'] ?? '/' ) ), 0, 500 );
	}
}
