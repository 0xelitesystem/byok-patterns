<?php
/**
 * Plugin Name:       BYOK Example
 * Plugin URI:        https://github.com/0xelitesystem/byok-patterns
 * Description:       Reference implementation of the server-side encrypted BYOK pattern. Admin enters an Anthropic API key once via Settings; the plugin encrypts it before storing and decrypts when needed. Read the source for the full pattern.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            0xelitesystem
 * Author URI:        https://github.com/0xelitesystem
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       byok-example
 *
 * This is a reference implementation, not a production plugin. See the README
 * in the parent folder for the security model and customization guide.
 */

// Block direct access. Standard WordPress hardening.
if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Constants. Centralize all string identifiers so renaming for production is
// a one-place change.
// ---------------------------------------------------------------------------
define('BYOK_EXAMPLE_OPTION_KEY', 'byok_example_encrypted_api_key');
define('BYOK_EXAMPLE_NONCE_ACTION', 'byok_example_save_key');
define('BYOK_EXAMPLE_AJAX_NONCE', 'byok_example_ajax');
define('BYOK_EXAMPLE_MENU_SLUG', 'byok-example-settings');
define('BYOK_EXAMPLE_API_BASE', 'https://api.anthropic.com/v1/messages');
define('BYOK_EXAMPLE_API_VERSION', '2023-06-01');
define('BYOK_EXAMPLE_MODEL', 'claude-sonnet-4-6');

// ---------------------------------------------------------------------------
// Encryption helpers. AES-256-CBC with a key derived from WordPress salts.
// The encryption key never exists at rest. It is derived in-memory from
// AUTH_KEY and AUTH_SALT, both defined in wp-config.php.
// ---------------------------------------------------------------------------

function byok_example_get_encryption_key() {
    // Two-constant derivation. If either constant is rotated by the site
    // owner, the existing stored key becomes unrecoverable, which is the
    // expected behavior. The site owner re-enters their key.
    if (!defined('AUTH_KEY') || !defined('AUTH_SALT')) {
        return false;
    }
    return hash('sha256', AUTH_KEY . AUTH_SALT, true);
}

function byok_example_encrypt($plaintext) {
    if (empty($plaintext)) {
        return false;
    }
    $derived_key = byok_example_get_encryption_key();
    if ($derived_key === false) {
        return false;
    }
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($iv_length);
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $derived_key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return false;
    }
    // Concatenate IV + ciphertext, then base64 for safe storage.
    return base64_encode($iv . $ciphertext);
}

function byok_example_decrypt($encoded) {
    if (empty($encoded)) {
        return false;
    }
    $derived_key = byok_example_get_encryption_key();
    if ($derived_key === false) {
        return false;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false) {
        return false;
    }
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($raw) <= $iv_length) {
        return false;
    }
    $iv = substr($raw, 0, $iv_length);
    $ciphertext = substr($raw, $iv_length);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $derived_key, OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) {
        return false;
    }
    return $plaintext;
}

// ---------------------------------------------------------------------------
// Storage helpers. Single accessor pattern; everything that needs the key
// goes through these two functions.
// ---------------------------------------------------------------------------

function byok_example_get_key() {
    $stored = get_option(BYOK_EXAMPLE_OPTION_KEY, '');
    if (empty($stored)) {
        return '';
    }
    $decrypted = byok_example_decrypt($stored);
    return $decrypted === false ? '' : $decrypted;
}

function byok_example_save_key($plaintext_key) {
    $encrypted = byok_example_encrypt($plaintext_key);
    if ($encrypted === false) {
        return false;
    }
    return update_option(BYOK_EXAMPLE_OPTION_KEY, $encrypted, false);
}

function byok_example_clear_key() {
    delete_option(BYOK_EXAMPLE_OPTION_KEY);
}

// ---------------------------------------------------------------------------
// Validation. Send a 1-token request to the Anthropic API to confirm the
// key works and has access to the model we plan to use.
// ---------------------------------------------------------------------------

function byok_example_validate_key($api_key) {
    if (empty($api_key) || !is_string($api_key) || strpos($api_key, 'sk-ant-') !== 0) {
        return new WP_Error('invalid_format', 'Anthropic API keys start with "sk-ant-".');
    }
    $response = wp_remote_post(BYOK_EXAMPLE_API_BASE, [
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => BYOK_EXAMPLE_API_VERSION,
        ],
        'body'    => wp_json_encode([
            'model'      => BYOK_EXAMPLE_MODEL,
            'max_tokens' => 1,
            'messages'   => [['role' => 'user', 'content' => 'hi']],
        ]),
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 401) {
        return new WP_Error('invalid_key', 'API key was rejected by Anthropic. Check that you copied it correctly.');
    }
    if ($code === 403) {
        return new WP_Error('no_access', 'Key is valid but lacks access to the requested model.');
    }
    if ($code !== 200) {
        $body = wp_remote_retrieve_body($response);
        return new WP_Error('api_error', sprintf('Anthropic API returned status %d.', $code));
    }
    return true;
}

// ---------------------------------------------------------------------------
// Public API. Plugins, themes, or other code in the same install call this
// to send a prompt. Returns the response text on success, WP_Error on failure.
// ---------------------------------------------------------------------------

function byok_example_send_prompt($prompt, $args = []) {
    $api_key = byok_example_get_key();
    if (empty($api_key)) {
        return new WP_Error('no_key', 'No API key configured. Visit Settings, BYOK Example.');
    }
    if (empty($prompt) || !is_string($prompt)) {
        return new WP_Error('bad_input', 'Prompt must be a non-empty string.');
    }
    $defaults = [
        'model'      => BYOK_EXAMPLE_MODEL,
        'max_tokens' => 512,
    ];
    $args = wp_parse_args($args, $defaults);

    $response = wp_remote_post(BYOK_EXAMPLE_API_BASE, [
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => BYOK_EXAMPLE_API_VERSION,
        ],
        'body'    => wp_json_encode([
            'model'      => $args['model'],
            'max_tokens' => $args['max_tokens'],
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
        'timeout' => 60,
    ]);
    if (is_wp_error($response)) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_Error('api_error', sprintf('Anthropic API returned status %d.', $code));
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['content'])) {
        return new WP_Error('bad_response', 'Anthropic returned an unexpected response shape.');
    }
    $text = '';
    foreach ($body['content'] as $block) {
        if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
            $text .= $block['text'];
        }
    }
    return $text;
}

// ---------------------------------------------------------------------------
// Admin menu and settings page.
// ---------------------------------------------------------------------------

add_action('admin_menu', 'byok_example_register_menu');
function byok_example_register_menu() {
    add_options_page(
        __('BYOK Example', 'byok-example'),
        __('BYOK Example', 'byok-example'),
        'manage_options',
        BYOK_EXAMPLE_MENU_SLUG,
        'byok_example_render_settings_page'
    );
}

function byok_example_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'byok-example'));
    }

    // Handle form submission.
    $message = '';
    $message_kind = '';
    if (
        isset($_POST['byok_example_action'])
        && $_POST['byok_example_action'] === 'save_key'
        && isset($_POST['byok_example_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['byok_example_nonce'])), BYOK_EXAMPLE_NONCE_ACTION)
    ) {
        $submitted_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        if ($submitted_key === '') {
            byok_example_clear_key();
            $message = __('Key cleared.', 'byok-example');
            $message_kind = 'updated';
        } else {
            $result = byok_example_validate_key($submitted_key);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_kind = 'error';
            } else {
                if (byok_example_save_key($submitted_key)) {
                    $message = __('Key validated and saved (encrypted).', 'byok-example');
                    $message_kind = 'updated';
                } else {
                    $message = __('Encryption failed. Check that AUTH_KEY and AUTH_SALT are defined in wp-config.php.', 'byok-example');
                    $message_kind = 'error';
                }
            }
        }
    }

    $has_key = (byok_example_get_key() !== '');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('BYOK Example', 'byok-example'); ?></h1>
        <p><?php echo esc_html__('Server-side encrypted Anthropic API key. The key is encrypted before storing and decrypted only when needed.', 'byok-example'); ?></p>

        <?php if ($message !== '') : ?>
            <div class="notice notice-<?php echo esc_attr($message_kind === 'error' ? 'error' : 'success'); ?>">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field(BYOK_EXAMPLE_NONCE_ACTION, 'byok_example_nonce'); ?>
            <input type="hidden" name="byok_example_action" value="save_key">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="byok_api_key"><?php echo esc_html__('Anthropic API Key', 'byok-example'); ?></label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="byok_api_key"
                            name="api_key"
                            value=""
                            placeholder="<?php echo $has_key ? esc_attr__('(saved, leave blank to keep)', 'byok-example') : 'sk-ant-...'; ?>"
                            class="regular-text"
                            autocomplete="off"
                            spellcheck="false">
                        <p class="description">
                            <?php echo esc_html__('Get a key at console.anthropic.com. The key is validated against Anthropic before saving.', 'byok-example'); ?>
                            <?php if ($has_key) : ?>
                                <br><strong><?php echo esc_html__('A key is currently saved.', 'byok-example'); ?></strong>
                                <?php echo esc_html__('Submit empty to clear it.', 'byok-example'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button($has_key ? __('Update key', 'byok-example') : __('Validate and save', 'byok-example')); ?>
        </form>

        <?php if ($has_key) : ?>
            <hr>
            <h2><?php echo esc_html__('Test the connection', 'byok-example'); ?></h2>
            <p><?php echo esc_html__('Sends a real prompt using your saved key. Costs fractions of a cent.', 'byok-example'); ?></p>
            <p>
                <label for="byok_test_prompt"><strong><?php echo esc_html__('Prompt', 'byok-example'); ?></strong></label><br>
                <textarea id="byok_test_prompt" rows="2" cols="60" class="large-text"><?php echo esc_textarea('What is the capital of France?'); ?></textarea>
            </p>
            <p>
                <button type="button" class="button button-primary" id="byok_test_btn"><?php echo esc_html__('Send test prompt', 'byok-example'); ?></button>
            </p>
            <div id="byok_test_status" style="margin-top:1rem;"></div>
            <pre id="byok_test_output" style="background:#f5f5f5;padding:1rem;border:1px solid #ddd;border-radius:3px;white-space:pre-wrap;display:none;"></pre>

            <script>
            (function () {
                var btn = document.getElementById('byok_test_btn');
                var promptEl = document.getElementById('byok_test_prompt');
                var statusEl = document.getElementById('byok_test_status');
                var outEl = document.getElementById('byok_test_output');
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce = <?php echo wp_json_encode(wp_create_nonce(BYOK_EXAMPLE_AJAX_NONCE)); ?>;

                btn.addEventListener('click', function () {
                    var prompt = (promptEl.value || '').trim();
                    if (!prompt) {
                        statusEl.textContent = 'Type a prompt first.';
                        return;
                    }
                    btn.disabled = true;
                    statusEl.textContent = 'Sending...';
                    outEl.style.display = 'none';
                    outEl.textContent = '';
                    var data = new FormData();
                    data.append('action', 'byok_example_test_prompt');
                    data.append('_ajax_nonce', nonce);
                    data.append('prompt', prompt);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            btn.disabled = false;
                            if (json && json.success) {
                                statusEl.textContent = '';
                                outEl.style.display = 'block';
                                outEl.textContent = json.data.text || '';
                            } else {
                                statusEl.textContent = (json && json.data && json.data.message) || 'Request failed.';
                            }
                        })
                        .catch(function (err) {
                            btn.disabled = false;
                            statusEl.textContent = 'Request failed: ' + (err && err.message ? err.message : 'unknown');
                        });
                });
            }());
            </script>
        <?php endif; ?>

        <hr>
        <h2><?php echo esc_html__('How this stores your key', 'byok-example'); ?></h2>
        <ol>
            <li><?php echo esc_html__('When you submit, the plugin validates the key against the Anthropic API first.', 'byok-example'); ?></li>
            <li><?php echo esc_html__('If valid, the key is encrypted with AES-256-CBC using a key derived from AUTH_KEY and AUTH_SALT in wp-config.php.', 'byok-example'); ?></li>
            <li><?php echo esc_html__('The encrypted value is stored in the wp_options table under a single option name.', 'byok-example'); ?></li>
            <li><?php echo esc_html__('Decryption happens in memory each time the key is needed for an API call. The plaintext key never persists.', 'byok-example'); ?></li>
            <li><?php echo esc_html__('Uninstalling the plugin removes the encrypted value entirely.', 'byok-example'); ?></li>
        </ol>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// AJAX handler for the test prompt button.
// ---------------------------------------------------------------------------

add_action('wp_ajax_byok_example_test_prompt', 'byok_example_handle_test_prompt');
function byok_example_handle_test_prompt() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'byok-example')], 403);
    }
    check_ajax_referer(BYOK_EXAMPLE_AJAX_NONCE);
    $prompt = isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '';
    if ($prompt === '') {
        wp_send_json_error(['message' => __('Prompt is required.', 'byok-example')], 400);
    }
    $result = byok_example_send_prompt($prompt);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 502);
    }
    wp_send_json_success(['text' => $result]);
}

// ---------------------------------------------------------------------------
// Uninstall hook. Removes the stored encrypted key when the plugin is
// deleted (not just deactivated).
// ---------------------------------------------------------------------------

register_uninstall_hook(__FILE__, 'byok_example_uninstall');
function byok_example_uninstall() {
    delete_option(BYOK_EXAMPLE_OPTION_KEY);
}
