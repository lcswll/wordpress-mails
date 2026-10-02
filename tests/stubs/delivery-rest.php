<?php
/**
 * Minimal WP_REST_Request / WP_REST_Response stand-ins for unit tests of REST callbacks.
 * Guarded, so another test file may define the same classes first.
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.DuplicateClassName.Found, Generic.CodeAnalysis.UnusedFunctionParameter -- signatures mirror WordPress.
 *
 * @package Mailspur
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Request with parameters accessible like an array.
	 *
	 * @implements ArrayAccess<string,mixed>
	 */
	class WP_REST_Request implements ArrayAccess {
		/** @var array<string,mixed> */
		private $params;

		/** @param array<string,mixed> $params */
		public function __construct( string $method = 'GET', string $route = '', array $params = array() ) {
			$this->params = $params;
		}

		/** @return mixed */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
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
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Response holding data, status and headers.
	 */
	class WP_REST_Response {
		/** @var mixed */
		private $data;
		/** @var array<string,string> */
		public $headers = array();

		/** @param mixed $data */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data = $data;
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}
	}
}
