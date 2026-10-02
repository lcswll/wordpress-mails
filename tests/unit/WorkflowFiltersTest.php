<?php
/**
 * Workflow module: filter sanitizing (export form / CLI) and source labels.
 *
 * @package Mailspur
 */

namespace Mailspur\Tests;

use Brain\Monkey\Functions;
use Mailspur\Modules\Workflow\Filters;
use Mailspur\Modules\Workflow\Sources;

final class WorkflowFiltersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_defaults(): void {
		$this->assertSame(
			array(
				'search'      => '',
				'in_body'     => false,
				'status'      => 'all',
				'after'       => '',
				'before'      => '',
				'source'      => '',
				'format'      => '',
				'attachments' => false,
				'notes'       => false,
				'orderby'     => 'date',
				'order'       => 'desc',
			),
			Filters::sanitize( array() )
		);
	}

	public function test_invalid_values_fall_back_to_defaults(): void {
		$clean = Filters::sanitize(
			array(
				'search'      => '  ' . str_repeat( 'ä', 250 ) . ' ',
				'in_body'     => 'yes',
				'status'      => 'deleted',
				'after'       => "2026-01-01' OR 1=1",
				'before'      => '2026-02-28',
				'source'      => array( 'x' ),
				'format'      => 'html',
				'attachments' => '1',
				'notes'       => 'false',
				'orderby'     => 'id; DROP',
				'order'       => 'asc',
				'page'        => 9,
			)
		);
		$this->assertSame( 200, mb_strlen( (string) $clean['search'] ) );
		$this->assertTrue( $clean['in_body'] );
		$this->assertSame( 'all', $clean['status'] );
		$this->assertSame( '', $clean['after'] );
		$this->assertSame( '2026-02-28', $clean['before'] );
		$this->assertSame( '', $clean['source'] );
		$this->assertSame( 'html', $clean['format'] );
		$this->assertTrue( $clean['attachments'] );
		$this->assertFalse( $clean['notes'] );
		$this->assertSame( 'date', $clean['orderby'] );
		$this->assertSame( 'asc', $clean['order'] );
		$this->assertArrayNotHasKey( 'page', $clean );
	}

	public function test_plugin_names_by_slug(): void {
		$this->assertSame(
			array(
				'woocommerce' => 'WooCommerce',
				'hello'       => 'Hello Dolly',
			),
			Sources::names(
				array(
					'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ),
					'woocommerce/other.php'       => array( 'Name' => 'Ignored second file' ),
					'hello.php'                   => array( 'Name' => 'Hello Dolly' ),
				)
			)
		);
	}

	public function test_source_labels(): void {
		Functions\when( 'wp_get_theme' )->alias(
			function ( $slug ) {
				return new class( $slug ) {
					/** @var string */
					private $slug;
					public function __construct( string $slug ) {
						$this->slug = $slug;
					}
					public function exists(): bool {
						return 'astra' === $this->slug;
					}
					public function get( string $key ): string {
						return 'Astra';
					}
				};
			}
		);
		$plugins = array( 'woocommerce' => 'WooCommerce' );
		$mu      = array( 'mailer' => 'Site Mailer' );

		$this->assertSame( 'WordPress', Sources::label( 'core', $plugins, $mu ) );
		$this->assertSame( 'WordPress', Sources::label( '', $plugins, $mu ) );
		$this->assertSame( 'Resent from log', Sources::label( 'mailspur:resend', $plugins, $mu ) );
		$this->assertSame( 'WooCommerce', Sources::label( 'plugin:woocommerce', $plugins, $mu ) );
		$this->assertSame( 'gone-plugin', Sources::label( 'plugin:gone-plugin', $plugins, $mu ) );
		$this->assertSame( 'Site Mailer (must-use plugin)', Sources::label( 'mu-plugin:mailer', $plugins, $mu ) );
		$this->assertSame( 'Astra (theme)', Sources::label( 'theme:astra', $plugins, $mu ) );
		$this->assertSame( 'old-theme (theme)', Sources::label( 'theme:old-theme', $plugins, $mu ) );
		$this->assertSame( 'Imported from WP Mail Logging', Sources::label( 'import:wp-mail-logging', $plugins, $mu ) );
		$this->assertSame( 'Imported from gone', Sources::label( 'import:gone', $plugins, $mu ) );
		$this->assertSame( 'thing', Sources::label( 'other:thing', $plugins, $mu ) );
	}

	public function test_sources_are_cached(): void {
		$cached = array(
			array(
				'value' => 'core',
				'label' => 'WordPress',
				'count' => 3,
			),
		);
		Functions\when( 'get_transient' )->justReturn( $cached );
		$this->assertSame( $cached, ( new Sources() )->all() );
		$this->assertSame( array(), $this->wpdb->prepared, 'No query while cached.' );
	}

	public function test_sources_query_groups_by_the_indexed_column(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_plugins' )->justReturn( array( 'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ) ) );
		Functions\when( 'get_mu_plugins' )->justReturn( array() );
		Functions\expect( 'set_transient' )->once()->with( Sources::TRANSIENT, \Mockery::type( 'array' ), Sources::TTL );
		$this->wpdb->results = array(
			array(
				array(
					'source' => 'plugin:woocommerce',
					'n'      => '40',
				),
				array(
					'source' => 'core',
					'n'      => '2',
				),
			),
		);

		$this->assertSame(
			array(
				array(
					'value' => 'plugin:woocommerce',
					'label' => 'WooCommerce',
					'count' => 40,
				),
				array(
					'value' => 'core',
					'label' => 'WordPress',
					'count' => 2,
				),
			),
			( new Sources() )->all()
		);
		$this->assertSame( 'SELECT source, COUNT(*) AS n FROM %i GROUP BY source ORDER BY n DESC LIMIT %d', $this->wpdb->prepared[0]['sql'] );
	}
}
