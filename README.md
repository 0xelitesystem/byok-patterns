# byok-patterns

Production reference implementations for "Bring Your Own Key" architectures. Each pattern is a complete, working example targeting a different deployment context. Pick the one that fits your stack, copy the code, adapt to your needs.

## What is BYOK?

The user supplies their own API key for the LLM provider (Anthropic, OpenAI, etc.) instead of you supplying yours. The user pays the API costs directly. You build product on top.

Why teams choose BYOK:
- **No cost exposure.** A viral spike in usage does not bankrupt you.
- **No payment infrastructure.** No Stripe, no metering, no per-token billing logic.
- **Privacy positioning.** "Your key, your data, your provider relationship" is a real selling point.
- **Faster to ship.** Skip the entire "how do we charge for API calls" problem.

The tradeoff: friction. Users must obtain a key, paste it in, and tolerate a setup step. For a developer audience this is fine. For a non-technical audience you need careful UX. See `browser-byok/` for an example UX.

## Pattern selection

| Your situation | Pattern | Why |
|---|---|---|
| Pure frontend tool, single-page app, browser-only | `browser-byok` | Key never leaves the user's browser. Zero infrastructure. |
| WordPress site, settings page, admins enter the key once | `wordpress-plugin` | Server-side encrypted storage with WordPress security primitives. |
| Need server-side processing, CORS-blocked APIs, or abuse-resistant rate limiting | `node-proxy` | Server proxy that forwards user keys without storing them. |

If you are unsure, start with `browser-byok`. It is the simplest and most common shape.

## Security model summary

| Pattern | Where the key lives | Risk surface |
|---|---|---|
| `browser-byok` | Browser memory for one session | XSS in your code = key theft. Trade for zero infrastructure. |
| `wordpress-plugin` | wp_options table, encrypted with site-specific key | DB compromise = decryptable if attacker also gets wp-config.php. Standard WP threat model. |
| `node-proxy` | In transit only, never persisted | Server compromise = no stored keys to steal. Logs must redact. |

None of these are "perfectly secure" because no client-controlled key system can be. The goal is to match security to the threat model the product actually faces.

## Common mistakes this repo helps you avoid

1. **Logging the key.** Every console.log, sentry breadcrumb, request log, and error report needs a redaction pass. Code in this repo demonstrates the pattern.
2. **Putting the key in a URL.** URL parameters end up in browser history, server access logs, referrer headers, analytics tools. Every pattern here uses POST bodies or headers.
3. **Storing the key in plaintext.** localStorage in plaintext is fine for low-stakes demos. For production, use the encrypted patterns shown.
4. **Forgetting to validate.** Users paste invalid keys, expired keys, keys for the wrong provider. Every pattern shows validation against a known-cheap endpoint before saving.
5. **Forgetting to clear.** When a user logs out or removes their key, every reference must be cleared. Includes in-memory variables, decrypted caches, retry queues.
6. **No rate limiting on proxies.** A proxy without per-key rate limiting becomes an abuse vector. The `node-proxy` example includes a working limiter.

## Provider compatibility

Every pattern in this repo demonstrates the Anthropic API as the example. Adapting to other providers is straightforward; the validation endpoint, header name, and response shape change. A future addition will include a multi-provider abstraction; for now, swap these three values:

| Provider | Key prefix | Validate endpoint | Header |
|---|---|---|---|
| Anthropic | `sk-ant-` | `POST /v1/messages` with `max_tokens: 1` | `x-api-key: <key>` |
| OpenAI | `sk-` | `GET /v1/models` | `Authorization: Bearer <key>` |
| Google AI | (varies) | `GET /v1beta/models?key=<key>` | (key in query for this one) |

## License

MIT. See [LICENSE](LICENSE).

## Related

This repo is part of a small cluster of practical Claude tooling:
- [prompt-templates](https://github.com/0xelitesystem/prompt-templates) — production prompts that target specific LLM failure modes
- [claude-skills-templates](https://github.com/0xelitesystem/claude-skills-templates) — five reference Skill patterns
- [readme-slop-checker](https://github.com/0xelitesystem/readme-slop-checker) — audit a README for AI-generated cliches
