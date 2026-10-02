<?php
/**
 * A feature module. Modules only use the documented extension points (docs/MODULES.md), so features
 * can be developed, tested and removed independently of each other.
 *
 * @package Mailspur
 */

namespace Mailspur;

defined( 'ABSPATH' ) || exit;

interface Module {

	public function __construct( Repository $repository );

	/** Hooks the module into WordPress. Runs on every request – keep it cheap. */
	public function register(): void;
}
