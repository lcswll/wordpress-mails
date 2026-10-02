<?php
/**
 * WP-CLI utility functions used by the plugin, for static analysis only (see class-wp-cli.php).
 *
 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
 *
 * @package Mailspur
 */

namespace WP_CLI\Utils;

/**
 * @param array<int,array<string,mixed>|object> $items
 * @param array<int,string>|string              $fields
 */
function format_items( string $format, array $items, $fields ): void {}
