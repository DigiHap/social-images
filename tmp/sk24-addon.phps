
/* ========================================================================
 * SEO / GEO / AEO-Ergänzung (22.11.2026)
 * 1. Strukturierte Daten (TouristTrip + Offer + Breadcrumb) für Angebote
 * 2. /llms.txt  – Überblick für KI-Suchen
 * 3. /faq.json  – alle FAQ der Seite maschinenlesbar
 * ===================================================================== */

function sk24x_page_desc( $id ) {
	foreach ( array( '_seopress_titles_desc', '_sk24_seo_desc' ) as $k ) {
		$v = trim( (string) get_post_meta( $id, $k, true ) );
		if ( $v !== '' ) { return sk24x_txt( $v ); }
	}
	$ex = (string) get_post_field( 'post_excerpt', $id );
	return sk24x_txt( $ex );
}

function sk24x_txt( $s ) {
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

function sk24x_title( $id ) {
	return sk24x_txt( get_the_title( $id ) );
}

function sk24x_is_noindex( $id ) {
	return get_post_meta( $id, '_seopress_robots_index', true ) === 'yes';
}

function sk24x_euro( $v ) {
	return number_format( (float) $v, 0, ',', '.' ) . ' €';
}

/* ---------- 1. Angebote: TouristTrip + Offer + BreadcrumbList ---------- */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'angebot' ) ) { return; }
	$id   = get_queried_object_id();
	$url  = get_permalink( $id );
	$home = home_url( '/' );
	$org  = $home . '#organization';

	$d     = function_exists( 'sk24p_fresh' ) ? sk24p_fresh( $id ) : null;
	$price = $d ? (float) $d['price'] : ( function_exists( 'sk24p_base_price' ) ? sk24p_base_price( $id ) : 0 );

	$img = get_the_post_thumbnail_url( $id, 'full' );
	if ( ! $img ) {
		$gal = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $id, 'sk24_galerie', true ) ) ) );
		if ( $gal ) { $img = wp_get_attachment_image_url( reset( $gal ), 'full' ); }
	}

	$trip = array(
		'@type'       => 'TouristTrip',
		'@id'         => $url . '#angebot',
		'name'        => sk24x_title( $id ),
		'url'         => $url,
		'inLanguage'  => 'de-DE',
		'touristType' => 'Skiurlauber',
		'provider'    => array( '@id' => $org ),
	);
	$desc = sk24x_page_desc( $id );
	$desc = trim( preg_replace( '/\s*\b[Aa]b\s+\d{1,3}(?:\.\d{3})*(?:,-|,\d+)?\s*(?:€|Euro)(?:\s*(?:p\.\s*P\.|pro Person))?\s*[,.]?/u', '', $desc ) );
	if ( $desc ) { $trip['description'] = $desc; }
	if ( $img ) { $trip['image'] = $img; }

	$regions = wp_get_post_terms( $id, 'angebot_region', array( 'fields' => 'names' ) );
	if ( ! is_wp_error( $regions ) && $regions ) {
		$trip['itinerary'] = array( '@type' => 'Place', 'name' => implode( ', ', $regions ) );
	}

	if ( $price > 0 ) {
		$p     = number_format( $price, 2, '.', '' );
		$offer = array(
			'@type'              => 'Offer',
			'price'              => $p,
			'priceCurrency'      => 'EUR',
			'url'                => $url,
			'availability'       => 'https://schema.org/InStock',
			'offeredBy'          => array( '@id' => $org ),
			'priceSpecification' => array(
				'@type'             => 'UnitPriceSpecification',
				'price'             => $p,
				'priceCurrency'     => 'EUR',
				'referenceQuantity' => array( '@type' => 'QuantitativeValue', 'value' => 1, 'unitText' => 'Person' ),
			),
		);
		if ( $d && ! empty( $d['retrieved'] ) ) {
			$offer['validFrom'] = gmdate( 'c', (int) $d['retrieved'] );
		}
		if ( $d && function_exists( 'sk24p_price_sentence' ) ) {
			$offer['description'] = sk24x_txt( sk24p_price_sentence( $d ) );
		}
		$trip['offers'] = $offer;
	}

	$crumbs = array( array( 'Startseite', $home ) );
	$ov     = get_page_by_path( 'angebote' );
	if ( $ov && $ov->post_status === 'publish' ) {
		$crumbs[] = array( sk24x_title( $ov->ID ), get_permalink( $ov ) );
	}
	$crumbs[] = array( $trip['name'], $url );
	$items    = array();
	foreach ( $crumbs as $i => $c ) {
		$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] );
	}

	$graph = array(
		'@context' => 'https://schema.org',
		'@graph'   => array(
			array(
				'@type' => 'Organization',
				'@id'   => $org,
				'name'  => 'skifahren24.com',
				'url'   => $home,
				'logo'  => $home . 'wp-content/uploads/2026/10/skifahren24-logo.webp',
			),
			$trip,
			array( '@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $items ),
		),
	);
	echo '<script type="application/ld+json" id="sk24x-angebot-schema">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 20 );

/* ---------- FAQ aus Seiten einsammeln (Elementor-HTML und Beitragstext) ---------- */
function sk24x_faq_of_post( $post_id ) {
	$sources = array( (string) get_post_field( 'post_content', $post_id ) );
	$raw     = get_post_meta( $post_id, '_elementor_data', true );
	if ( $raw && strpos( $raw, 'FAQPage' ) !== false ) {
		$data = json_decode( $raw, true );
		$walk = function ( $els ) use ( &$walk, &$sources ) {
			foreach ( (array) $els as $el ) {
				if ( ! is_array( $el ) ) { continue; }
				if ( ! empty( $el['settings']['html'] ) && is_string( $el['settings']['html'] ) ) {
					$sources[] = $el['settings']['html'];
				}
				if ( ! empty( $el['elements'] ) ) { $walk( $el['elements'] ); }
			}
		};
		$walk( $data );
	}
	$out = array();
	foreach ( $sources as $src ) {
		if ( strpos( $src, 'FAQPage' ) === false ) { continue; }
		if ( ! preg_match_all( '#<script[^>]*ld\+json[^>]*>(.*?)</script>#s', $src, $m ) ) { continue; }
		foreach ( $m[1] as $js ) {
			$j = json_decode( trim( $js ), true );
			if ( ! is_array( $j ) ) { continue; }
			$items = isset( $j['@graph'] ) ? $j['@graph'] : array( $j );
			foreach ( (array) $items as $it ) {
				if ( ! is_array( $it ) || ( $it['@type'] ?? '' ) !== 'FAQPage' ) { continue; }
				foreach ( (array) ( $it['mainEntity'] ?? array() ) as $q ) {
					if ( ! empty( $q['name'] ) && ! empty( $q['acceptedAnswer']['text'] ) ) {
						$out[ sk24x_txt( $q['name'] ) ] = sk24x_txt( $q['acceptedAnswer']['text'] );
					}
				}
			}
		}
	}
	return $out;
}

function sk24x_public_ids( $type ) {
	$ids = get_posts( array(
		'post_type'      => $type,
		'post_status'    => 'publish',
		'posts_per_page' => 500,
		'fields'         => 'ids',
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'has_password'   => false,
	) );
	return array_values( array_filter( $ids, function ( $i ) { return ! sk24x_is_noindex( $i ); } ) );
}

/* ---------- 2./3. llms.txt und faq.json ---------- */
add_action( 'parse_request', function () {
	$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
	if ( $path !== 'llms.txt' && $path !== 'faq.json' ) { return; }

	$key   = 'sk24x_' . ( $path === 'faq.json' ? 'faq' : 'llms' );
	$body  = get_transient( $key );
	if ( $body === false ) {
		$body = $path === 'faq.json' ? sk24x_build_faq() : sk24x_build_llms();
		set_transient( $key, $body, HOUR_IN_SECONDS );
	}
	status_header( 200 );
	header( 'X-Robots-Tag: noindex' );
	header( 'Cache-Control: public, max-age=3600' );
	header( 'Content-Type: ' . ( $path === 'faq.json' ? 'application/json' : 'text/plain' ) . '; charset=utf-8' );
	echo $body; // phpcs:ignore
	exit;
}, 1 );

function sk24x_build_faq() {
	$front = (int) get_option( 'page_on_front' );
	$ids   = array_unique( array_merge( $front ? array( $front ) : array(), sk24x_public_ids( 'page' ), sk24x_public_ids( 'post' ) ) );
	$pages = array();
	$total = 0;
	foreach ( $ids as $id ) {
		$f = sk24x_faq_of_post( $id );
		if ( ! $f ) { continue; }
		$qa = array();
		foreach ( $f as $q => $a ) { $qa[] = array( 'frage' => $q, 'antwort' => $a ); }
		$total  += count( $qa );
		$pages[] = array( 'seite' => sk24x_title( $id ), 'url' => get_permalink( $id ), 'fragen' => $qa );
	}
	return wp_json_encode( array(
		'website'     => 'skifahren24.com',
		'url'         => home_url( '/' ),
		'sprache'     => 'de-DE',
		'stand'       => wp_date( 'Y-m-d' ),
		'anzahl'      => $total,
		'seiten'      => $pages,
	), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
}

function sk24x_build_llms() {
	$home  = home_url( '/' );
	$front = (int) get_option( 'page_on_front' );
	$desc  = $front ? sk24x_page_desc( $front ) : get_bloginfo( 'description' );
	$L     = array();
	$L[]   = '# skifahren24.com';
	$L[]   = '';
	$L[]   = '> ' . ( $desc ? $desc : 'Skiurlaub und Skireisen günstig buchen.' );
	$L[]   = '';
	$L[]   = 'skifahren24.com ist ein deutschsprachiges Reiseportal für Skiurlaub in den Alpen und den Mittelgebirgen. Die Seite stellt Skigebiete und Regionen vor, vergleicht Skireisen und zeigt Angebote mit tagesaktuellen Ab-Preisen pro Person. Gebucht wird über eine angebundene Buchungsmaske, Preise und Verfügbarkeit werden dort verbindlich bestätigt.';
	$L[]   = '';

	$skip  = array( 'impressum', 'datenschutz', 'danke', 'kontakt', 'ueber-uns', 'blog', 'angebote', 'ziele', 'faq' );
	$hide  = array( 'privacy-policy', 'danke' );
	$reg   = array();
	$other = array();
	foreach ( sk24x_public_ids( 'page' ) as $id ) {
		if ( $id === $front ) { continue; }
		$slug = get_post_field( 'post_name', $id );
		if ( in_array( $slug, $hide, true ) ) { continue; }
		$line = '- [' . sk24x_title( $id ) . '](' . get_permalink( $id ) . ')';
		$d    = sk24x_page_desc( $id );
		if ( $d ) { $line .= ': ' . $d; }
		if ( in_array( $slug, $skip, true ) ) { $other[ $slug ] = $line; continue; }
		$reg[] = $line;
	}
	if ( $reg ) {
		$L[] = '## Skigebiete, Regionen und Themen';
		$L[] = '';
		$L   = array_merge( $L, $reg );
		$L[] = '';
	}

	$off = array();
	foreach ( sk24x_public_ids( 'angebot' ) as $id ) {
		$line = '- [' . sk24x_title( $id ) . '](' . get_permalink( $id ) . ')';
		$d    = function_exists( 'sk24p_fresh' ) ? sk24p_fresh( $id ) : null;
		if ( $d ) {
			$line .= ': ab ' . sk24x_euro( $d['price'] ) . ' pro Person';
			if ( ! empty( $d['nights'] ) ) { $line .= ', ' . (int) $d['nights'] . ' Nächte'; }
			$line .= ' (Preisstand ' . wp_date( 'd.m.Y', (int) $d['retrieved'] ) . ')';
		}
		$off[] = $line;
	}
	if ( $off ) {
		$L[] = '## Aktuelle Skireise-Angebote';
		$L[] = '';
		$L[] = 'Die Preise sind Ab-Preise pro Person und ändern sich laufend je nach Termin und Verfügbarkeit.';
		$L[] = '';
		$L   = array_merge( $L, $off );
		$L[] = '';
	}

	$posts = array();
	foreach ( sk24x_public_ids( 'post' ) as $id ) {
		$line = '- [' . sk24x_title( $id ) . '](' . get_permalink( $id ) . ')';
		$d    = sk24x_page_desc( $id );
		if ( $d ) { $line .= ': ' . $d; }
		$posts[] = $line;
	}
	if ( $posts ) {
		$L[] = '## Ratgeber';
		$L[] = '';
		$L   = array_merge( $L, $posts );
		$L[] = '';
	}

	$L[] = '## Weitere Informationen';
	$L[] = '';
	foreach ( array( 'ziele', 'angebote', 'blog', 'faq', 'ueber-uns', 'kontakt', 'impressum', 'datenschutz' ) as $s ) {
		if ( isset( $other[ $s ] ) ) { $L[] = $other[ $s ]; }
	}
	$L[] = '- [Häufige Fragen (maschinenlesbar)](' . $home . 'faq.json)';
	$L[] = '- [Sitemap](' . $home . 'sitemaps.xml)';
	$L[] = '';
	return implode( "\n", $L );
}

/* Cache leeren, sobald Inhalte gespeichert werden */
add_action( 'save_post', function () {
	delete_transient( 'sk24x_llms' );
	delete_transient( 'sk24x_faq' );
} );
