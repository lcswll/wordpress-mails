<?php
/**
 * Minimal WP_REST_Request / WP_REST_Response stand-ins for unit tests (only what the plugin uses).
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 *
 * @package Mailspur
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Request with method and route.
	 */
	class WP_REST_Request {
		/** @var string */
		private $method;
		/** @var string */
		private $route;

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Response with data and status.
	 */
	class WP_REST_Response {
		/** @var mixed */
		private $data;
		/** @var int */
		private $status;

		/**
		 * @param mixed $data
		 */
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

		public function is_error(): bool {
			return $this->status >= 400;
		}
	}
}
