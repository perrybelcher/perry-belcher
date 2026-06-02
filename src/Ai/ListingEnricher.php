<?php
/**
 * AI-assisted listing intake.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Ai;

use Lodestar\DirectoryType\FieldDefinition;
use Lodestar\DirectoryType\FieldManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a pasted URL/blurb into a *proposed* listing (title, description,
 * fields, FAQ) for human review. It never saves anything — the proposal flows
 * back to the submission form for the user to confirm or edit.
 *
 * Prompt building and response parsing are pure/tested. Parsed fields are
 * whitelisted to the directory type's known keys, so the model cannot inject
 * arbitrary data.
 */
final class ListingEnricher {

	/**
	 * @param AiClient     $client AI client.
	 * @param FieldManager $fields Field definitions source.
	 */
	public function __construct(
		private AiClient $client,
		private FieldManager $fields,
	) {}

	/**
	 * Produce a draft proposal from raw input.
	 *
	 * @param string   $input      URL or free text.
	 * @param int      $type_id    Directory type ID.
	 * @param int|null $listing_id Related listing (for the log).
	 * @return array{ok:bool,title?:string,description?:string,fields?:array<string,mixed>,faq?:array<int,array{q:string,a:string}>,error?:string}
	 */
	public function enrich( string $input, int $type_id, ?int $listing_id = null ): array {
		$input = trim( $input );
		if ( '' === $input ) {
			return array( 'ok' => false, 'error' => 'empty input' );
		}
		if ( ! $this->client->is_available() ) {
			return array( 'ok' => false, 'error' => 'AI is not configured' );
		}

		$defs     = $this->fields->forType( $type_id );
		$response = $this->client->complete(
			'enrich',
			self::system_prompt(),
			array( array( 'role' => 'user', 'content' => self::user_prompt( $input, $defs ) ) ),
			array( 'max_tokens' => 1500 ),
			$listing_id
		);

		if ( ! $response->ok ) {
			return array( 'ok' => false, 'error' => $response->error );
		}

		$proposal       = self::parse_proposal( $response->text, $defs );
		$proposal['ok'] = true;

		return $proposal;
	}

	/**
	 * System prompt (pure).
	 */
	public static function system_prompt(): string {
		return 'You are a directory-listing assistant. Extract structured listing data from the '
			. 'user\'s input. Respond with ONLY a JSON object — no prose, no code fences. Never invent '
			. 'facts that are not supported by the input.';
	}

	/**
	 * User prompt describing the desired JSON for this type (pure).
	 *
	 * @param string            $input Raw input.
	 * @param FieldDefinition[] $defs  Field definitions.
	 */
	public static function user_prompt( string $input, array $defs ): string {
		$lines = array();
		foreach ( $defs as $def ) {
			$line = '- ' . $def->fieldKey . ' (' . $def->inputType . ')';
			if ( ! empty( $def->options ) ) {
				$values = array();
				foreach ( $def->options as $k => $v ) {
					$values[] = is_int( $k ) ? (string) $v : (string) $k;
				}
				$line .= ' one of: ' . implode( ', ', $values );
			}
			$lines[] = $line;
		}

		return "INPUT:\n" . $input . "\n\n"
			. "Return JSON with keys: title (string), description (string), "
			. "fields (object keyed by these field keys), faq (array of {q, a}).\n"
			. "Available fields:\n" . implode( "\n", $lines );
	}

	/**
	 * Parse the model's JSON, whitelisting fields to known keys (pure).
	 *
	 * @param string            $text Model output.
	 * @param FieldDefinition[] $defs Field definitions.
	 * @return array{title:string,description:string,fields:array<string,mixed>,faq:array<int,array{q:string,a:string}>}
	 */
	public static function parse_proposal( string $text, array $defs ): array {
		$empty = array( 'title' => '', 'description' => '', 'fields' => array(), 'faq' => array() );

		$data = json_decode( self::extract_json( $text ), true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		$allowed = array();
		foreach ( $defs as $def ) {
			$allowed[ $def->fieldKey ] = true;
		}

		$fields = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $key => $value ) {
			if ( isset( $allowed[ $key ] ) ) {
				$fields[ (string) $key ] = $value;
			}
		}

		$faq = array();
		foreach ( (array) ( $data['faq'] ?? array() ) as $pair ) {
			if ( is_array( $pair ) && isset( $pair['q'], $pair['a'] ) ) {
				$faq[] = array( 'q' => (string) $pair['q'], 'a' => (string) $pair['a'] );
			}
		}

		return array(
			'title'       => (string) ( $data['title'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'fields'      => $fields,
			'faq'         => $faq,
		);
	}

	/**
	 * Extract the first balanced JSON object from text (pure).
	 *
	 * Tolerates code fences / surrounding prose.
	 *
	 * @param string $text Model output.
	 */
	public static function extract_json( string $text ): string {
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return '';
		}

		return substr( $text, $start, $end - $start + 1 );
	}
}
