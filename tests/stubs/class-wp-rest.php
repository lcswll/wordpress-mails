<?php
/**
 * Minimal WP_REST_Request / WP_REST_Response stand-ins for unit tests – shared by all modules
 * (loaded by tests/bootstrap.php). Only what the plugin uses; extend here instead of adding copies.
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter -- signatures mirror WordPress.
 *
 * @package Mailspur
 */

/**
 * Request with method, route and parameters accessible like an array.
 *
 * @implements ArrayAccess<string,mixed>
 */
class WP_REST_Request implements ArrayAccess {
	/** @var string */
	private $method;
	/** @var string */
	private $route;
	/** @var array<string,mixed> */
	private $params;

	/** @param array<string,mixed> $params */
	public function __construct( string $method = 'GET', string $route = '', array $params = array() ) {
		$this->method = $method;
		$this->route  = $route;
		$this->params = $params;
	}

	public function get_method(): string {
		return $this->method;
	}

	public function get_route(): string {
		return $this->route;
	}

	/** @return mixed */
	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}

	/** @param mixed $value */
	public function set_param( string $key, $value ): void {
		$this->params[ $key ] = $value;
	}

	/** @param mixed $offset */
	public function offsetExists( $offset ): bool {
		return isset( $this->params[ $offset ] );
	}

	/**
	 * @param mixed $offset
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->params[ $offset ] ?? null;
	}

	/**
	 * @param mixed $offset
	 * @param mixed $value
	 */
	public function offsetSet( $offset, $value ): void {
		$this->params[ $offset ] = $value;
	}

	/** @param mixed $offset */
	public function offsetUnset( $offset ): void {
		unset( $this->params[ $offset ] );
	}
}

/**
 * Response holding data, status and headers.
 */
class WP_REST_Response {
	/** @var mixed */
	private $data;
	/** @var int */
	private $status;
	/** @var array<string,string> */
	public $headers = array();

	/** @param mixed $data */
	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	/** @return mixed */
	public function get_data() {
		return $this->data;
	}

	/** @param mixed $data */
	public function set_data( $data ): void {
		$this->data = $data;
	}

	public function get_status(): int {
		return $this->status;
	}

	public function set_status( int $status ): void {
		$this->status = $status;
	}

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	public function is_error(): bool {
		return $this->status >= 400;
	}
}
