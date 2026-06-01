<?php
/**
 * Phase 1 performance gate — faceted + radius search benchmark.
 *
 * Exercises the REAL {@see \Lodestar\Data\QueryBuilder} output against a seeded
 * dataset and times a 3-facet + radius, sorted, paginated query (plus its
 * COUNT companion, exactly as ListingRepository::search() runs them).
 *
 * Two drivers:
 *   - sqlite (default): self-contained, runs anywhere PHP has the sqlite3
 *     extension with math functions. Proves the access pattern is index-driven
 *     (EXISTS facet lookups + lat/lng bounding box), not a postmeta self-join.
 *   - mysql: the AUTHORITATIVE gate. Point it at a real MySQL/MariaDB to verify
 *     the <150ms-on-50k DoD on the target engine:
 *         LODESTAR_BENCH_DRIVER=mysql \
 *         LODESTAR_BENCH_MYSQL_DSN="mysql:host=127.0.0.1;dbname=wp;charset=utf8mb4" \
 *         LODESTAR_BENCH_MYSQL_USER=root LODESTAR_BENCH_MYSQL_PASS=secret \
 *         php tests/benchmark/facet-benchmark.php
 *
 * Usage: php tests/benchmark/facet-benchmark.php [listings] [iterations]
 *
 * @package Lodestar
 */

declare(strict_types=1);

// The data classes guard on ABSPATH; define it so we can load them standalone.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

$src = dirname( __DIR__, 2 ) . '/src';
require_once $src . '/PostType/ListingPostType.php';
require_once $src . '/Data/Facet.php';
require_once $src . '/Data/GeoFilter.php';
require_once $src . '/Data/SearchQuery.php';
require_once $src . '/Data/QueryBuilder.php';

use Lodestar\Data\Facet;
use Lodestar\Data\GeoFilter;
use Lodestar\Data\QueryBuilder;
use Lodestar\Data\SearchQuery;

$listings   = (int) ( $argv[1] ?? 50000 );
$iterations = (int) ( $argv[2] ?? 30 );
$driver     = getenv( 'LODESTAR_BENCH_DRIVER' ) ?: 'sqlite';
$prefix     = 'wp_';

/*
 * The representative query the DoD calls for: a directory type + THREE facets
 * + a radius filter, sorted by relevance, first page.
 */
$query = new SearchQuery(
	directoryTypeId: 1,
	facets: array(
		Facet::text( 'cuisine', array( 'italian' ) ),
		Facet::text( 'wifi', array( 'yes' ) ),
		Facet::range( 'price_tier', 2.0, 4.0 ),
	),
	geo: new GeoFilter( 40.5, -74.0, 10.0 ),
	orderBy: SearchQuery::ORDER_RELEVANCE,
	page: 1,
	perPage: 20,
	status: 'publish',
);

$builder              = new QueryBuilder( $prefix );
[ $sql, $args ]       = $builder->build( $query );
[ $count_sql, $cargs ] = $builder->buildCount( $query );

fwrite( STDOUT, "Lodestar Phase 1 — facet + radius benchmark\n" );
fwrite( STDOUT, str_repeat( '-', 60 ) . "\n" );
fwrite( STDOUT, "driver={$driver}  listings={$listings}  iterations={$iterations}\n\n" );

$runner = 'mysql' === $driver
	? bench_mysql( $prefix, $listings )
	: bench_sqlite( $prefix, $listings );

// Warm up.
$runner['run']( $sql, $args );
$runner['run']( $count_sql, $cargs );

$rows  = $runner['run']( $sql, $args );
$total = $runner['run']( $count_sql, $cargs );

$main_times  = bench_time( $runner['run'], $sql, $args, $iterations );
$count_times = bench_time( $runner['run'], $count_sql, $cargs, $iterations );

$count_total = is_array( $total ) ? (int) ( $total[0]['c'] ?? reset( $total[0] ) ) : 0;

fwrite( STDOUT, 'result rows (page):  ' . count( $rows ) . "\n" );
fwrite( STDOUT, 'total matches:       ' . $count_total . "\n\n" );

report( 'SELECT (page)', $main_times );
report( 'COUNT (total)', $count_times );

$combined_median = median( $main_times ) + median( $count_times );
fwrite( STDOUT, str_repeat( '-', 60 ) . "\n" );
fwrite( STDOUT, sprintf( "combined median (SELECT+COUNT): %.2f ms\n", $combined_median ) );

if ( isset( $runner['explain'] ) ) {
	fwrite( STDOUT, "\nquery plan (SELECT):\n" );
	foreach ( $runner['explain']( $sql, $args ) as $line ) {
		fwrite( STDOUT, '  ' . $line . "\n" );
	}
}

$gate = 150.0;
fwrite( STDOUT, "\nDoD gate: combined < {$gate}ms on 50k — " );
fwrite( STDOUT, ( $combined_median < $gate ? 'PASS' : 'OVER' ) . sprintf( " (%.2f ms)\n", $combined_median ) );
if ( 'sqlite' === $driver ) {
	fwrite( STDOUT, "(SQLite proxy: proves index-driven access pattern; run the mysql driver for the authoritative number.)\n" );
}

exit( $combined_median < $gate ? 0 : 1 );


/* ------------------------------------------------------------------ helpers */

/**
 * Convert $wpdb placeholders (%d/%s/%f) to positional `?` and return the
 * ordered placeholder types so each driver can bind correctly.
 *
 * @return array{0:string,1:array<int,string>} [ sql, types ]
 */
function to_positional( string $sql ): array {
	preg_match_all( '/%[dsf]/', $sql, $m );
	$types = array_map( static fn ( $t ) => $t[1], $m[0] );
	$sql   = preg_replace( '/%[dsf]/', '?', $sql );

	return array( $sql, $types );
}

/**
 * Time a runner N times and return the per-call milliseconds.
 *
 * @return array<int,float>
 */
function bench_time( callable $run, string $sql, array $args, int $iterations ): array {
	$times = array();
	for ( $i = 0; $i < $iterations; $i++ ) {
		$start = hrtime( true );
		$run( $sql, $args );
		$times[] = ( hrtime( true ) - $start ) / 1e6;
	}
	sort( $times );

	return $times;
}

function median( array $sorted ): float {
	$n = count( $sorted );
	if ( 0 === $n ) {
		return 0.0;
	}
	$mid = intdiv( $n, 2 );

	return 0 === $n % 2 ? ( $sorted[ $mid - 1 ] + $sorted[ $mid ] ) / 2 : $sorted[ $mid ];
}

function report( string $label, array $times ): void {
	fwrite(
		STDOUT,
		sprintf(
			"%-14s min %6.2f ms | median %6.2f ms | p95 %6.2f ms | max %6.2f ms\n",
			$label,
			$times[0],
			median( $times ),
			$times[ (int) floor( count( $times ) * 0.95 ) ] ?? end( $times ),
			end( $times )
		)
	);
}

/**
 * SQLite driver: build schema, seed, and return a query runner closure.
 *
 * @return array{run:callable,explain:callable}
 */
function bench_sqlite( string $prefix, int $listings ): array {
	$db = new SQLite3( ':memory:' );
	$db->enableExceptions( true );
	$db->exec( 'PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF;' );

	$ld = $prefix . 'lodestar_';

	$db->exec(
		"CREATE TABLE {$ld}listing_data (
			listing_id INTEGER PRIMARY KEY, directory_type_id INTEGER, plan_id INTEGER,
			lat REAL, lng REAL, geohash TEXT, price REAL, rating_avg REAL, rating_count INTEGER,
			is_featured INTEGER, featured_until TEXT, expires_at TEXT, claim_status TEXT,
			ai_citability_score INTEGER, view_count INTEGER, meta TEXT, created_at TEXT, updated_at TEXT
		);
		CREATE TABLE {$ld}field_index (
			id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER, field_key TEXT,
			value_text TEXT, value_num REAL, value_date TEXT
		);
		CREATE TABLE {$prefix}posts ( ID INTEGER PRIMARY KEY, post_status TEXT, post_type TEXT );"
	);

	$cuisines = array( 'italian', 'mexican', 'thai', 'indian', 'chinese', 'american' );

	$db->exec( 'BEGIN' );
	$ins_d = $db->prepare( "INSERT INTO {$ld}listing_data
		(listing_id,directory_type_id,plan_id,lat,lng,price,rating_avg,rating_count,is_featured,view_count,created_at,updated_at)
		VALUES (:id,:dt,1,:lat,:lng,:price,:rating,10,:feat,:views,'2026-01-01 00:00:00','2026-01-01 00:00:00')" );
	$ins_p = $db->prepare( "INSERT INTO {$prefix}posts (ID,post_status,post_type) VALUES (:id,:st,'ld_listing')" );
	$ins_f = $db->prepare( "INSERT INTO {$ld}field_index (listing_id,field_key,value_text,value_num)
		VALUES (:id,:k,:vt,:vn)" );

	for ( $i = 1; $i <= $listings; $i++ ) {
		$lat = 40.0 + mt_rand( 0, 1000000 ) / 1000000.0;            // 40.0 .. 41.0
		$lng = -74.5 + mt_rand( 0, 1000000 ) / 1000000.0;           // -74.5 .. -73.5
		$dt  = mt_rand( 1, 100 ) <= 80 ? 1 : 2;

		$ins_d->bindValue( ':id', $i, SQLITE3_INTEGER );
		$ins_d->bindValue( ':dt', $dt, SQLITE3_INTEGER );
		$ins_d->bindValue( ':lat', $lat, SQLITE3_FLOAT );
		$ins_d->bindValue( ':lng', $lng, SQLITE3_FLOAT );
		$ins_d->bindValue( ':price', mt_rand( 0, 100000 ) / 100.0, SQLITE3_FLOAT );
		$ins_d->bindValue( ':rating', mt_rand( 0, 500 ) / 100.0, SQLITE3_FLOAT );
		$ins_d->bindValue( ':feat', mt_rand( 1, 100 ) <= 5 ? 1 : 0, SQLITE3_INTEGER );
		$ins_d->bindValue( ':views', mt_rand( 0, 5000 ), SQLITE3_INTEGER );
		$ins_d->execute();
		$ins_d->reset();

		$ins_p->bindValue( ':id', $i, SQLITE3_INTEGER );
		$ins_p->bindValue( ':st', mt_rand( 1, 100 ) <= 90 ? 'publish' : 'pending', SQLITE3_TEXT );
		$ins_p->execute();
		$ins_p->reset();

		$facets = array(
			array( 'cuisine', $cuisines[ array_rand( $cuisines ) ], null ),
			array( 'wifi', mt_rand( 0, 1 ) ? 'yes' : 'no', null ),
			array( 'open_now', mt_rand( 0, 1 ) ? 'yes' : 'no', null ),
			array( 'price_tier', null, (float) mt_rand( 1, 4 ) ),
			array( 'ld_category', null, (float) mt_rand( 1, 50 ) ),
			array( 'ld_location', null, (float) mt_rand( 1, 200 ) ),
		);
		foreach ( $facets as [$k, $vt, $vn] ) {
			$ins_f->bindValue( ':id', $i, SQLITE3_INTEGER );
			$ins_f->bindValue( ':k', $k, SQLITE3_TEXT );
			$ins_f->bindValue( ':vt', $vt, null === $vt ? SQLITE3_NULL : SQLITE3_TEXT );
			$ins_f->bindValue( ':vn', $vn, null === $vn ? SQLITE3_NULL : SQLITE3_FLOAT );
			$ins_f->execute();
			$ins_f->reset();
		}
	}
	$db->exec( 'COMMIT' );

	// Indexes mirror the production schema (SDD §3).
	$db->exec(
		"CREATE INDEX d_dt ON {$ld}listing_data (directory_type_id);
		CREATE INDEX d_latlng ON {$ld}listing_data (lat,lng);
		CREATE INDEX d_price ON {$ld}listing_data (price);
		CREATE INDEX d_rating ON {$ld}listing_data (rating_avg);
		CREATE INDEX d_feat ON {$ld}listing_data (is_featured);
		CREATE INDEX f_text ON {$ld}field_index (field_key,value_text);
		CREATE INDEX f_num ON {$ld}field_index (field_key,value_num);
		CREATE INDEX f_listing ON {$ld}field_index (listing_id);
		CREATE INDEX p_type_status ON {$prefix}posts (post_type,post_status);"
	);
	$db->exec( 'ANALYZE' );

	$bind = static function ( SQLite3Stmt $stmt, array $types, array $args ): void {
		foreach ( $args as $idx => $value ) {
			$pos  = $idx + 1;
			$type = $types[ $idx ] ?? 's';
			if ( 'd' === $type ) {
				$stmt->bindValue( $pos, (int) $value, SQLITE3_INTEGER );
			} elseif ( 'f' === $type ) {
				$stmt->bindValue( $pos, (float) $value, SQLITE3_FLOAT );
			} else {
				$stmt->bindValue( $pos, (string) $value, SQLITE3_TEXT );
			}
		}
	};

	return array(
		'run'     => static function ( string $sql, array $args ) use ( $db, $bind ) {
			[ $psql, $types ] = to_positional( $sql );
			$stmt             = $db->prepare( $psql );
			$bind( $stmt, $types, $args );
			$res  = $stmt->execute();
			$rows = array();
			while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
				$rows[] = $row;
			}
			return $rows;
		},
		'explain' => static function ( string $sql, array $args ) use ( $db, $bind ): array {
			[ $psql, $types ] = to_positional( $sql );
			$stmt             = $db->prepare( 'EXPLAIN QUERY PLAN ' . $psql );
			$bind( $stmt, $types, $args );
			$res   = $stmt->execute();
			$lines = array();
			while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
				$lines[] = $row['detail'] ?? implode( ' ', $row );
			}
			return $lines;
		},
	);
}

/**
 * MySQL driver via PDO (authoritative gate). Seeds the same dataset.
 *
 * @return array{run:callable}
 */
function bench_mysql( string $prefix, int $listings ): array {
	$dsn  = getenv( 'LODESTAR_BENCH_MYSQL_DSN' );
	$user = getenv( 'LODESTAR_BENCH_MYSQL_USER' ) ?: 'root';
	$pass = getenv( 'LODESTAR_BENCH_MYSQL_PASS' ) ?: '';

	if ( ! $dsn ) {
		fwrite( STDERR, "LODESTAR_BENCH_MYSQL_DSN is required for the mysql driver.\n" );
		exit( 2 );
	}

	$pdo = new PDO( $dsn, $user, $pass, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
	$ld  = $prefix . 'lodestar_';

	// Seed only if the dataset is not already present at the requested size.
	$have = (int) $pdo->query( "SELECT COUNT(*) FROM {$ld}listing_data" )->fetchColumn();
	if ( $have < $listings ) {
		fwrite( STDOUT, "seeding MySQL ({$listings} listings)...\n" );
		$pdo->exec( "DELETE FROM {$ld}field_index; DELETE FROM {$ld}listing_data;" );
		$cuisines = array( 'italian', 'mexican', 'thai', 'indian', 'chinese', 'american' );
		$pdo->beginTransaction();
		$d = $pdo->prepare( "INSERT INTO {$ld}listing_data
			(listing_id,directory_type_id,lat,lng,price,rating_avg,is_featured,view_count,created_at,updated_at)
			VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())" );
		$f = $pdo->prepare( "INSERT INTO {$ld}field_index (listing_id,field_key,value_text,value_num) VALUES (?,?,?,?)" );
		for ( $i = 1; $i <= $listings; $i++ ) {
			$d->execute(
				array(
					$i,
					mt_rand( 1, 100 ) <= 80 ? 1 : 2,
					40.0 + mt_rand( 0, 1000000 ) / 1000000.0,
					-74.5 + mt_rand( 0, 1000000 ) / 1000000.0,
					mt_rand( 0, 100000 ) / 100.0,
					mt_rand( 0, 500 ) / 100.0,
					mt_rand( 1, 100 ) <= 5 ? 1 : 0,
					mt_rand( 0, 5000 ),
				)
			);
			$f->execute( array( $i, 'cuisine', $cuisines[ array_rand( $cuisines ) ], null ) );
			$f->execute( array( $i, 'wifi', mt_rand( 0, 1 ) ? 'yes' : 'no', null ) );
			$f->execute( array( $i, 'price_tier', null, mt_rand( 1, 4 ) ) );
			$f->execute( array( $i, 'ld_category', null, mt_rand( 1, 50 ) ) );
			if ( 0 === $i % 5000 ) {
				$pdo->commit();
				$pdo->beginTransaction();
			}
		}
		$pdo->commit();
	}

	return array(
		'run' => static function ( string $sql, array $args ) use ( $pdo ) {
			[ $psql, ] = to_positional( $sql );
			$stmt       = $pdo->prepare( $psql );
			$stmt->execute( array_values( $args ) );
			return $stmt->fetchAll( PDO::FETCH_ASSOC );
		},
	);
}
