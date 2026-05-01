# wordpress-plugin

Single-file WordPress plugin demonstrating server-side encrypted BYOK storage. Admin enters their API key once via a settings page; the plugin encrypts it and stores it in the `wp_options` table; subsequent calls retrieve and decrypt as needed.

## When to use this pattern

- WordPress plugins or themes that call an LLM API server-side
- Sites where a single admin holds the key (not multi-user BYOK)
- Use cases where the key needs to be available for cron jobs or background tasks

## When NOT to use this pattern

- If different users on the same site need different keys (this stores one key per site)
- If your environment has shared hosting with weak file system isolation (the encryption is only as good as wp-config.php's protection)
- If you can do everything client-side (use `browser-byok` instead, lower attack surface)

## Install

1. Copy `byok-example.php` into `wp-content/plugins/byok-example/` on your WordPress install.
2. Activate "BYOK Example" from Plugins.
3. Visit Settings, BYOK Example.
4. Paste your Anthropic API key. The plugin validates it against the Anthropic API before saving.
5. Click "Send test prompt" to confirm the round trip works.

## Security model

The key is encrypted before it is written to the database, and decrypted when needed. The encryption key is derived from two WordPress constants: `AUTH_KEY` and `AUTH_SALT`, both defined in `wp-config.php`.

This means:

- A database dump alone is not enough to recover the key. The attacker also needs `wp-config.php`.
- A `wp-config.php` leak alone is not enough either. The attacker also needs the database.
- If the attacker has both, the key is decryptable. This is the same threat model as WordPress's own user authentication.

The plugin uses AES-256-CBC via OpenSSL. Each save generates a fresh IV. The IV is stored alongside the ciphertext in the same option value.

## Implementation notes

This file is heavily commented. Read it as a tutorial, not just a copy-paste reference.

Key functions:

- `byok_example_encrypt($plaintext)` and `byok_example_decrypt($ciphertext)` — crypto helpers
- `byok_example_validate_key($key)` — calls Anthropic to confirm the key works
- `byok_example_get_key()` — single accessor for retrieving the decrypted key when needed
- `byok_example_clear_key()` — full removal, used on uninstall
- `byok_example_settings_page()` — admin UI rendered via the Settings API
- `byok_example_handle_test_prompt()` — AJAX handler that demonstrates a real API call

Security primitives used:

- `current_user_can('manage_options')` capability check on every admin action
- `wp_verify_nonce()` on every form submission and AJAX call
- `sanitize_text_field()` on inputs before validation
- `esc_html()` and `esc_attr()` on every output to the page
- `register_uninstall_hook()` to clear the key on plugin removal

## Customizing for production

This is a reference, not a finished plugin. To use in production:

1. Rename the plugin slug. Replace every occurrence of `byok_example_` and `byok-example` with your own prefix.
2. Add a multi-key option if your use case needs it. The current pattern stores one key per site.
3. Add logging hooks if you need them. The reference omits all logging for clarity; production may need an audit trail.
4. Add a key rotation reminder. Production keys should be rotated periodically.
5. Add an option export pattern if your plugin supports site migration. Encrypted keys do not migrate; admins must re-enter on the new site.

## Attribution

Part of [byok-patterns](https://github.com/0xelitesystem/byok-patterns).
