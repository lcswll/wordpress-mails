# Feature modules

Features beyond the core log live in **modules**: self-contained classes plus their own assets, translations and
tests. Modules only use the extension points below – they never edit core files. That keeps features independent
(they can be built in parallel, reviewed and removed one by one).

## Layout of a module `foo`

| Path | What |
| --- | --- |
| `mailspur-email-log/src/Modules/Foo/Module.php` | `Mailspur\Modules\Foo\Module implements Mailspur\Module` – entry point |
| `mailspur-email-log/src/Modules/Foo/*.php` | further classes (namespace `Mailspur\Modules\Foo`, PSR-4, one class per file) |
| `mailspur-email-log/assets/foo.js`, `foo.css` | admin assets (optional) |
| `i18n/de_DE/foo.json` | German translations of the module's strings |
| `tests/unit/Foo*Test.php` | PHPUnit + Brain Monkey unit tests |
| `tests/e2e/features/foo.php` | integration test inside real WordPress (Playground), see below |
| `tests/e2e/foo.spec.js` | Playwright browser test (optional) |
| `tests/e2e/seed.d/foo.php` | extra seed data for the browser tests (optional) |

Register the module by adding its class to `mailspur-email-log/src/Modules.php`.

```php
namespace Mailspur\Modules\Foo;

use Mailspur\Repository;

final class Module implements \Mailspur\Module {
	private $repository;
	public function __construct( Repository $repository ) { $this->repository = $repository; }
	public function register(): void { /* add_filter / add_action … – keep it cheap, runs on every request */ }
}
```

## Rules

- Prefix everything: hooks/options/transients/cron events `mailspur_`, extra tables `{$wpdb->prefix}mailspur_*`.
  Uninstall and deactivation clean these up generically – do not edit `uninstall.php`.
- **No schema changes** to the log table. Store per-mail data in the `meta` JSON column (via `mailspur_meta`),
  use `notes` (count of hints) and `size` (bytes) where they fit. The indexed `delivery` column holds the status
  reported by the email provider (`Repository::DELIVERY`, written by the Delivery module only; list filter
  `delivery`). Need something else? Ask in your report.
- Logging must never slow down or break mail delivery: no network/DNS calls while a mail is being sent,
  everything wrapped defensively (the logger already catches exceptions from filters).
- **No external services, CDNs or remote assets** (wordpress.org rule and the plugin's privacy promise).
  Everything is bundled; JS without build step, vanilla, `textContent` only (ESLint `no-unsanitized`).
- Escape all output, sanitize all input, check capabilities (`Settings::current_user_can_view()` to read the log,
  `manage_options` for anything that changes settings or sends mail). REST routes: namespace `Rest::NS`,
  `permission_callback` always set, args with schema.
- Strings: `__( 'Literal', 'mailspur-email-log' )` & friends only with literal strings (no `_n`/`_x` – the
  extractor does not support them). Add the German translation to `i18n/de_DE/foo.json`, run `npm run i18n`.
- Quality gate before you finish: `npm run verify` must be green (PHPCS WordPress-Extra, PHPStan level 8,
  PHPUnit, ESLint, readiness, translations, Plugin Check rules, e2e incl. browser tests, ZIP).

## PHP extension points

### Logging (`Logger`)

| Hook | Type | Use |
| --- | --- | --- |
| `mailspur_meta( array $meta, string $phase, mixed $context )` | filter | Collect per-mail data. Phases: `capture` (context: `wp_mail()` args, before sending), `phpmailer` (context: the `PHPMailer` instance in `phpmailer_init` – not fired for API mailers using `pre_wp_mail`), `result` (context: `['status' => int\|null, 'error' => string]`), `import` (context: the mapped row of an imported mail). The contexts carry the mail as sent; the stored row (and `mailspur_finalize_row`) has secrets masked (`Redactor`, setting `redact_secrets`). Never copy secrets from a context into the meta. Return the meta array; store your data under your module key, e.g. `$meta['trace'] = …`. |
| `mailspur_finalize_row( array $data, array $row )` | filter | Last step before the final UPDATE (and for every imported row). `$row` is the complete row incl. `message`, `headers`, `meta` (array). Set columns in `$data`, e.g. `$data['notes'] = 3;` or change `$data['meta']` / `$data['status']`. |
| `mailspur_logged( int $id, array $row )` | action | After the final UPDATE of a logged mail (not for imports). For counters/alerts – keep it cheap. |
| `mailspur_should_log( bool $log, array $atts )` | filter | Skip logging a mail. |
| `Logger::$current_source` | static | Source of the mail captured last (`plugin:woocommerce`), for `pre_wp_mail` handlers that decide per sender (e.g. bundled email types). Read-only. |
| `mailspur_delivery_status( int $id, string $status, string $before, array $row )` | action | The provider status of a log entry changed (webhook; slugs of `Repository::DELIVERY`, `$before` '' = none; `$row`: id, created_at, source, subject). The Types module moves its per-type counters. |

Statuses (`Repository::STATUS_*`): `PENDING` (0, unknown), `SENT` (1), `FAILED` (2), `HELD` (3 – deliberately
not delivered, e.g. staging mode). The reason is in `meta.delivery.held` (`staging`, `brake`, `problem_recipient`,
`bundled` …). When a held mail is sent on its own ("Send now"), the action `mailspur_held_released( array $row )` fires.

### Retention (`Cleanup`)

| Hook | Type | Use |
| --- | --- | --- |
| `mailspur_retention_rules( array $rules )` | filter | Own periods for single kinds of email: add `array( 'source' => 'plugin:x', 'days' => 7, 'match' => fn( string $subject ): bool )`; `days` = `Cleanup::UNTIL_LIMIT` keeps them until the maximum number of entries. Only senders with a rule are read entry by entry. |
| `mailspur_retention_expire( int[] $ids )` | filter | Entries whose own (shorter) period ended, before deletion. Return the ids to delete (the anonymisation keeps them anonymised instead). |

### REST payloads (`Rest`)

| Hook | Use |
| --- | --- |
| `mailspur_rest_summary( array $item, array $row )` | Fields per list row (keep it small). Core already includes `notes` and `size`. |
| `mailspur_rest_item( array $item, array $row )` | Fields of the detail view (dialog). Core includes `meta` (decoded array). |

Own routes: `register_rest_route( \Mailspur\Rest::NS, '/foo', … )` on `rest_api_init`.

### Admin (`Admin`)

| Hook | Use |
| --- | --- |
| `mailspur_admin_tabs( array $tabs )` | Add a top tab: `$tabs['foo'] = array( __( 'Foo', … ), $capability );` (Settings always stays last). |
| `mailspur_render_tab_{key}` | Render your tab's content (inside `.wrap.mailspur`). |
| `mailspur_admin_enqueue( string $tab, string $assets_url )` | Enqueue your assets. On the `log` tab, make your script depend on `mailspur-email-log-admin` to use `window.mailspur`. Pass config via `wp_add_inline_script( …, 'before' )`. |
| `mailspur_settings_defaults( array $defaults )` | Add setting keys with defaults (scalars). Read with `Settings::get( 'key' )`. |
| `mailspur_settings_sanitize( array $clean, array $input )` | Sanitize your keys (missing checkbox = false). |
| `mailspur_settings_sections( array $settings, string $option_name )` | Render `<h2>` + `<table class="form-table">` rows inside the settings form; inputs named `{$option_name}[key]`. |
| `mailspur_settings_after()` | Content below the settings form (tools, checks; own forms/REST calls). |
| `mailspur_delivery_filter( bool $show )` | Offer the log's "Provider status" filter although no entry has a status yet (Delivery: a provider is configured). |

### JavaScript API (`window.mailspur`, log tab)

```js
const m = window.mailspur;
m.registerTab( { id: 'foo', label: 'Foo', render( container, mail ) { container.append( m.node( 'p', '', mail.subject ) ); } } );
m.registerRowDecorator( ( tr, item ) => { /* add badge to tr.querySelector( '.col-subject' ) */ } );
m.registerDetail( ( dl, mail ) => { /* append <dt>/<dd> */ } );
m.registerAction( { id: 'foo', label: 'Foo', icon: 'download', visible: ( mail ) => true, run: async ( mail ) => {} } );
m.onList( ( data ) => {} );
m.state(); // copy of the list state: filters (search, status, source, format …), sorting, page
m.api( 'mails/1' ); m.toast( 'Done' ); m.reload(); m.current(); m.open( 42 ); m.fmt( '%s of %s', 1, 2 ); m.node( 'span', 'cls', 'text' );
```

Register synchronously when your script runs (scripts are deferred; the list renders on `DOMContentLoaded`).

## Tests

- **Unit** (`tests/unit/`): extend `Mailspur\Tests\TestCase` (Brain Monkey, recording `$wpdb` fake).
- **Integration** (`tests/e2e/features/foo.php`): runs inside real WordPress after the plugin is active and before the
  core self-test. Write `/e2e-out/features/foo.json` as `{ "passed": n, "failed": n, "results": [ { "ok", "name", "detail" } ] }`
  and **do not throw** (later steps must still run). Clean up what you created. See `tests/e2e/import-test.php`.
- **Browser** (`tests/e2e/foo.spec.js`): Playwright against the seeded Playground (`tests/e2e/seed.php` + `seed.d/`).
  Use your own seed data; other specs run in the same site (serially).

`npm run test:e2e` runs all of it (`E2E_PORT` env selects the server port).
