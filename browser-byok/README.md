# browser-byok

Single-file HTML demo of the simplest BYOK pattern. The user pastes their Anthropic API key, the page validates it against the Anthropic API, and the key is held in a JavaScript variable for the duration of the session. Closing the tab discards the key.

## When to use this pattern

- Pure frontend tools with no backend
- Single-page apps where each user brings their own key
- Demos, prototypes, internal tools
- Products targeting a developer audience comfortable with API keys

## When NOT to use this pattern

- If your users are non-technical and will paste their key on every visit
- If you need server-side rate limiting or abuse prevention
- If your code has any third-party scripts you do not control (XSS risk)
- If you need to make API calls when the user is not actively viewing the page

## Run it

Open `index.html` in any modern browser. No build, no server.

For a hosted version, push to any static host (GitHub Pages, Netlify, Cloudflare Pages, S3 bucket). The whole tool is one file.

## What the demo does

1. Asks for an Anthropic API key.
2. Validates the key by sending a 1-token request to the Messages API. Cheap, reliable, immediate feedback.
3. Stores the key in a `let` variable. Not in localStorage. Not in sessionStorage. Not in a cookie. Not in a URL.
4. Lets the user run a small example prompt to confirm everything works.
5. Provides a "Clear key" button that wipes the variable.

## Security notes

This pattern relies on three things:

1. **The key is in memory only.** Closing the tab kills it. Refreshing the page kills it. There is nothing on disk to steal.
2. **The page has no third-party scripts.** Every script tag in `index.html` points to nothing external. No analytics, no fonts, no CDN libraries. This is intentional. Adding any third-party script to a BYOK page exposes the key if that script ever turns malicious.
3. **The key only travels to api.anthropic.com over HTTPS.** Confirmed in the network tab. The validation request and the demo request both go to the same origin.

The known weakness: XSS in your own code. If an attacker can inject script into your page (via a vulnerable CMS, a compromised dependency, or user-generated content rendered without sanitization), they can read the in-memory key and exfiltrate it. Mitigations:

- Build with strict Content Security Policy headers (no inline scripts, no eval).
- Audit every dependency before adding it.
- Treat all user-rendered content as hostile (escape on output).
- For higher-stakes apps, move to the `node-proxy` pattern where the key never enters the browser at all.

## CORS and the dangerous-direct-browser-access header

The Anthropic API blocks browser requests by default. To allow them, set the header `anthropic-dangerous-direct-browser-access: true`. This is the documented, supported way to call the API from a browser. The "dangerous" in the name reflects that browser-direct access is inherently riskier than server-side access (CORS preflight, abuse potential), not that this header itself is dangerous.

For high-volume production, prefer the `node-proxy` pattern.

## Customizing

The demo uses one CSS file embedded in the page (the dark/light theme can be retitled or replaced) and one JavaScript module (the `BYOKClient` class can be lifted out and used in any framework). All API calls go through one method, `BYOKClient.send()`, so swapping providers means changing three constants at the top of the script.

## Attribution

Part of [byok-patterns](https://github.com/0xelitesystem/byok-patterns).
