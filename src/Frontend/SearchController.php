<?php
/**
 * Front-end faceted search + map.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

use Lodestar\Data\ListingRepository;
use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldDefinition;
use Lodestar\DirectoryType\FieldManager;
use Lodestar\Geo\Geocoder;
use Lodestar\Support\Request;
use Lodestar\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[lodestar_search]` renders a faceted search form whose results filter live
 * against the custom tables (via the Phase 1 QueryBuilder), with radius search
 * ordered by distance and an optional clustering map.
 *
 * Reads are idempotent GET requests — no nonce needed — but every value still
 * passes through the Request sanitiser and the allowlisted SearchRequest mapper,
 * and output is escaped. A request can only facet on fields the type marked
 * searchable.
 */
final class SearchController {

	public function __construct(
		private ListingRepository $repo,
		private DirectoryTypeManager $types,
		private FieldManager $fields,
		private TemplateLoader $templates,
	) {}

	/**
	 * Register shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'lodestar_search', array( $this, 'render' ) );
	}

	/**
	 * Render the search UI + results.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'type' => '', 'per_page' => 20, 'map' => '1' ),
			(array) $atts,
			'lodestar_search'
		);

		$type = $this->resolve_type( (string) $atts['type'] );
		if ( ! $type ) {
			return esc_html__( 'No directory type is configured yet.', 'lodestar' );
		}

		Assets::enqueue_style();

		$defs = $this->fields->forType( $type->id );
		$args = $this->collect_args( $defs );

		$query  = SearchRequest::build( $args, $defs, $type->id, Settings::default_radius_km() );
		$result = $this->repo->search( $query );

		$rows    = array();
		$markers = array();
		foreach ( $result->items as $row ) {
			$id    = (int) ( $row->listing_id ?? 0 );
			$title = get_the_title( $id );
			$url   = (string) get_permalink( $id );

			$rows[] = array(
				'title'    => $title,
				'url'      => $url,
				'distance' => isset( $row->distance ) ? round( (float) $row->distance, 1 ) : null,
				'rating'   => isset( $row->rating_avg ) ? (float) $row->rating_avg : null,
			);

			if ( ! empty( $row->lat ) && ! empty( $row->lng ) ) {
				$markers[] = array( 'lat' => (float) $row->lat, 'lng' => (float) $row->lng, 'title' => $title, 'url' => $url );
			}
		}

		$map_html = '';
		if ( '0' !== (string) $atts['map'] && $markers ) {
			Assets::enqueue_map();
			$map_html = MapRenderer::render( $markers );
		}

		return $this->templates->render(
			'search',
			array(
				'action_url'      => esc_url( remove_query_arg( 'page' ) ),
				'facet_controls'  => $this->facet_controls( $defs, $args ),
				'location_controls' => $this->location_controls( $args ),
				'sort_control'    => $this->sort_control( $args ),
				'has_geo'         => null !== $query->geo,
				'map_html'        => $map_html,
				'rows'            => $rows,
				'total'           => $result->total,
				'pagination'      => $this->pagination( $result->page, $result->pages(), $args ),
			)
		);
	}

	/*
	 * --------------------------------------------------------------------- *
	 *  Internals
	 * --------------------------------------------------------------------- */

	/**
	 * Gather sanitised search args from the query string (+ geocode an address).
	 *
	 * @param FieldDefinition[] $defs Field definitions.
	 * @return array<string,mixed>
	 */
	private function collect_args( array $defs ): array {
		$args = array(
			'sort'     => Request::getKey( 'sort', 'relevance' ),
			'page'     => Request::getInt( 'page', 1 ),
			'lat'      => Request::getText( 'lat' ),
			'lng'      => Request::getText( 'lng' ),
			'radius_km' => Request::getText( 'radius_km' ),
		);

		foreach ( $defs as $def ) {
			if ( ! $def->isFacetable || ! $def->isIndexable() ) {
				continue;
			}
			$key = $def->fieldKey;
			if ( FieldDefinition::INPUT_NUMBER === $def->inputType ) {
				$args[ $key . '_min' ] = Request::getText( $key . '_min' );
				$args[ $key . '_max' ] = Request::getText( $key . '_max' );
			} else {
				$value = Request::getTextOrArray( $key );
				if ( null !== $value ) {
					$args[ $key ] = $value;
				}
			}
		}

		// Address search: geocode "near" into lat/lng when coordinates absent.
		$near = Request::getText( 'near' );
		if ( '' !== $near && ( '' === (string) $args['lat'] || '' === (string) $args['lng'] ) ) {
			$point = Geocoder::from_config()->geocode( $near );
			if ( $point ) {
				$args['lat'] = $point->lat;
				$args['lng'] = $point->lng;
			}
		}

		return $args;
	}

	/**
	 * Build escaped facet filter controls for the form.
	 *
	 * @param FieldDefinition[]   $defs Field definitions.
	 * @param array<string,mixed> $args Current args.
	 */
	private function facet_controls( array $defs, array $args ): string {
		$html = '';
		foreach ( $defs as $def ) {
			if ( ! $def->isFacetable || ! $def->isIndexable() ) {
				continue;
			}

			$key = $def->fieldKey;
			$html .= '<div class="lodestar-facet lodestar-facet--' . esc_attr( $def->inputType ) . '">';
			$html .= '<span class="lodestar-facet__label">' . esc_html( $def->label ) . '</span>';

			if ( FieldDefinition::INPUT_NUMBER === $def->inputType ) {
				$html .= $this->number_range( $key, (string) ( $args[ $key . '_min' ] ?? '' ), (string) ( $args[ $key . '_max' ] ?? '' ) );
			} elseif ( in_array( $def->inputType, array( FieldDefinition::INPUT_SELECT, FieldDefinition::INPUT_MULTISELECT ), true ) && ! empty( $def->options ) ) {
				$html .= $this->options_control( $def, (array) ( $args[ $key ] ?? array() ) );
			} else {
				$value = is_array( $args[ $key ] ?? '' ) ? '' : (string) ( $args[ $key ] ?? '' );
				$html .= '<input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="lodestar-input" />';
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Checkboxes for a select/multiselect facet (whitelisted options).
	 *
	 * @param FieldDefinition $def      Field definition.
	 * @param array<int,mixed> $selected Selected values.
	 */
	private function options_control( FieldDefinition $def, array $selected ): string {
		$selected = array_map( 'strval', $selected );
		$multiple = FieldDefinition::INPUT_MULTISELECT === $def->inputType;
		$name     = esc_attr( $def->fieldKey . ( $multiple ? '[]' : '' ) );

		$html = '';
		foreach ( $def->options as $opt_key => $opt_label ) {
			$value = is_int( $opt_key ) ? (string) $opt_label : (string) $opt_key;
			$html .= '<label class="lodestar-facet__option"><input type="' . ( $multiple ? 'checkbox' : 'radio' ) . '" name="' . $name . '" value="' . esc_attr( $value ) . '"' .
				( in_array( $value, $selected, true ) ? ' checked' : '' ) . ' /> ' . esc_html( (string) $opt_label ) . '</label>';
		}

		return $html;
	}

	/**
	 * A min/max numeric range control.
	 *
	 * @param string $key Field key.
	 * @param string $min Current min.
	 * @param string $max Current max.
	 */
	private function number_range( string $key, string $min, string $max ): string {
		return '<input type="number" step="any" name="' . esc_attr( $key . '_min' ) . '" value="' . esc_attr( $min ) . '" class="lodestar-input lodestar-input--min" placeholder="' . esc_attr__( 'Min', 'lodestar' ) . '" /> ' .
			'<input type="number" step="any" name="' . esc_attr( $key . '_max' ) . '" value="' . esc_attr( $max ) . '" class="lodestar-input lodestar-input--max" placeholder="' . esc_attr__( 'Max', 'lodestar' ) . '" />';
	}

	/**
	 * Location (address + radius) controls.
	 *
	 * @param array<string,mixed> $args Current args.
	 */
	private function location_controls( array $args ): string {
		$near   = Request::getText( 'near' );
		$radius = (string) ( $args['radius_km'] ?? '' );

		return '<div class="lodestar-facet lodestar-facet--location">' .
			'<span class="lodestar-facet__label">' . esc_html__( 'Near', 'lodestar' ) . '</span>' .
			'<input type="text" name="near" value="' . esc_attr( $near ) . '" class="lodestar-input" placeholder="' . esc_attr__( 'Address or place', 'lodestar' ) . '" />' .
			'<input type="number" step="any" name="radius_km" value="' . esc_attr( $radius ) . '" class="lodestar-input lodestar-input--radius" placeholder="' . esc_attr__( 'km', 'lodestar' ) . '" />' .
			'</div>';
	}

	/**
	 * Sort dropdown.
	 *
	 * @param array<string,mixed> $args Current args.
	 */
	private function sort_control( array $args ): string {
		$current = (string) ( $args['sort'] ?? 'relevance' );
		$options = array(
			'relevance'  => __( 'Relevance', 'lodestar' ),
			'distance'   => __( 'Distance', 'lodestar' ),
			'price_low'  => __( 'Price: low to high', 'lodestar' ),
			'price_high' => __( 'Price: high to low', 'lodestar' ),
			'rating'     => __( 'Rating', 'lodestar' ),
			'newest'     => __( 'Newest', 'lodestar' ),
		);

		$html = '<select name="sort" class="lodestar-select">';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * Prev/next pagination links preserving the current query.
	 *
	 * @param int                 $page  Current page.
	 * @param int                 $pages Total pages.
	 * @param array<string,mixed> $args  Current args.
	 */
	private function pagination( int $page, int $pages, array $args ): string {
		if ( $pages <= 1 ) {
			return '';
		}

		$base = remove_query_arg( array( 'page' ) );
		$html = '<nav class="lodestar-pagination">';

		if ( $page > 1 ) {
			$html .= '<a class="lodestar-button" href="' . esc_url( add_query_arg( 'page', $page - 1, $base ) ) . '">' . esc_html__( 'Previous', 'lodestar' ) . '</a>';
		}
		/* translators: 1: current page, 2: total pages. */
		$html .= '<span class="lodestar-pagination__status">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'lodestar' ), $page, $pages ) ) . '</span>';
		if ( $page < $pages ) {
			$html .= '<a class="lodestar-button" href="' . esc_url( add_query_arg( 'page', $page + 1, $base ) ) . '">' . esc_html__( 'Next', 'lodestar' ) . '</a>';
		}

		return $html . '</nav>';
	}

	/**
	 * Resolve a directory type from a slug, falling back to the first.
	 *
	 * @param string $slug Type slug.
	 */
	private function resolve_type( string $slug ) {
		if ( '' !== $slug ) {
			return $this->types->findBySlug( $slug );
		}
		$all = $this->types->all();

		return $all[0] ?? null;
	}
}
