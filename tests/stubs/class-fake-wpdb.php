<?php
/**
 * Recording $wpdb stand-in: writes and prepared queries are recorded, reads return queued results.
 *
 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- signatures mirror wpdb.
 *
 * @package Mailspur
 */

/**
 * Records every write and every prepared query; reads return queued results.
 */
class Fake_WPDB {
	/** @var string */
	public $prefix = 'wp_';
	/** @var int */
	public $insert_id = 0;
	/** @var array<int,array{0:string,1:string,2:array<string,mixed>,3?:array<string,mixed>}> */
	public $writes = array();
	/** @var array<int,array{sql:string,args:array<int,mixed>}> */
	public $prepared = array();
	/** @var array<int,mixed> */
	public $results = array();

	/** @param array<string,mixed> $data */
	public function insert( string $table, array $data ): int {
		$this->writes[] = array( 'insert', $table, $data );
		++$this->insert_id;
		return 1;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $where
	 */
	public function update( string $table, array $data, array $where ): int {
		$this->writes[] = array( 'update', $table, $data, $where );
		return 1;
	}

	/** @param mixed ...$args */
	public function prepare( string $sql, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->prepared[] = array(
			'sql'  => $sql,
			'args' => $args,
		);
		return $sql;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/** @return mixed */
	public function get_results( string $sql, string $output = '' ) {
		return array_shift( $this->results ) ?? array();
	}

	/** @return mixed */
	public function get_var( string $sql ) {
		return array_shift( $this->results );
	}

	/** @return mixed */
	public function get_row( string $sql, string $output = '' ) {
		return array_shift( $this->results );
	}

	/** @return int */
	public function query( string $sql ) {
		return (int) array_shift( $this->results );
	}
}
