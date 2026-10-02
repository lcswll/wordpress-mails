<?php
/**
 * Enabled feature modules (classes implementing Mailspur\Module), in load order.
 *
 * @package Mailspur
 */

defined( 'ABSPATH' ) || exit;

return array(
	Mailspur\Modules\Trace\Module::class,
	Mailspur\Modules\Notes\Module::class,
	Mailspur\Modules\Delivery\Module::class,
	Mailspur\Modules\Insights\Module::class,
	Mailspur\Modules\Workflow\Module::class,
	Mailspur\Modules\Types\Module::class,
);
