<?php
/**
 * Enabled feature modules (classes implementing Mailspur\Module), in load order.
 *
 * @package Mailspur
 */

defined( 'ABSPATH' ) || exit;

return array(
	Mailspur\Modules\Insights\Module::class,
);
