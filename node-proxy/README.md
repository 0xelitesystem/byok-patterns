# node-proxy

Server-side proxy that forwards user-supplied API keys to Anthropic. The user's key flows through the proxy in transit but is never stored, never logged, never persisted. Use this when client-side BYOK is not viable.

## When to use this pattern

- You need server-side processing (e.g. parsing the response, fan-out to multiple providers, retry logic)
- The client is a mobile app or another environment where direct browser calls are impractical
- You need per-key rate limiting to prevent abuse
- You want to add request validation, content filtering, or cost caps before forwarding

## When NOT to use this pattern

- If you can do everything in the browser, use `browser-byok` instead. It is simpler, has no server costs, and exposes a smaller attack surface.
- If you want to charge users (i.e. the keys are yours, not theirs), this is not BYOK and a different architecture applies.

## Run it

Requirements: Node 18 or later, or Bun. No other dependencies.

```bash
cd node-proxy
node server.js
```

The server listens on port 3000 by default. Override with `PORT=8080 node server.js`.

## How clients use it

Clients send a `POST /api/messages` request with two things:

1. The user's Anthropic API key in an `x-user-api-key` header.
2. The Anthropic Messages API request body.

The proxy validates the request, applies rate limiting keyed off the user's API key, and forwards to Anthropic. The response comes back unchanged.

Example client call from a browser:

```javascript
const response = await fetch('https://your-proxy.example.com/api/messages', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'x-user-api-key': userApiKey
  },
  body: JSON.stringify({
    model: 'claude-sonnet-4-6',
    max_tokens: 512,
    messages: [{ role: 'user', content: 'hi' }]
  })
});
```

## Security model

What the proxy does NOT do:

- It does not store the user's API key in any database, file, or persistent cache.
- It does not log the API key value. Logs redact it by default; the key never appears in any log line.
- It does not log full request or response bodies by default. Only request metadata (timestamp, status, latency, route).
- It does not make calls on behalf of users when they are not actively requesting (no background jobs holding keys).

What the proxy DOES do:

- Holds the key in memory for the duration of a single request, then drops it.
- Rate limits each unique key to 60 requests per minute by default. Adjustable via env var.
- Validates request shape before forwarding (rejects malformed bodies, oversized prompts).
- Returns Anthropic's error messages directly to the client without modification.

## Rate limiting

The proxy uses an in-memory rate limiter keyed off a hash of the API key. Use a hash, not the key itself, so the limiter map cannot be inspected to recover keys.

The default is 60 requests per minute per key. Adjust with `RATE_LIMIT_PER_MIN=30 node server.js`.

For multi-instance deployments (multiple proxy servers behind a load balancer), replace the in-memory limiter with Redis. The hash-the-key approach is the same; only the storage backend changes. Search for `// RATE_LIMITER` in `server.js` for the swap point.

## CORS

CORS is configured to accept requests from any origin by default. For production, restrict to your own domains by setting `ALLOWED_ORIGINS=https://yoursite.com,https://www.yoursite.com`.

## Deploying

This proxy is a single file with zero dependencies. Deploy options:

- **Bun on Fly.io / Railway / Render**: `bun server.js`. Zero-config.
- **Node on Vercel / Netlify Functions**: wrap the request handler in their function signature. The core logic in `handleRequest()` is portable.
- **Docker**: a 3-line Dockerfile copies `server.js` and runs `node server.js`. No `npm install` step required.
- **Bare VPS**: `pm2 start server.js` and `nginx` reverse proxy. Done.

## What this proxy does NOT include (intentionally)

For a reference, less is more. To keep the file readable:

- No authentication on the proxy itself. Anyone with a user's API key can use the proxy. This is correct for BYOK; the user IS authenticating with their own Anthropic key.
- No request body size limits beyond a sanity check. Production should add one (e.g. reject > 1 MB).
- No streaming response support. The reference forwards one-shot responses. For streaming, swap the response handling for `ReadableStream` piping.
- No metrics, no tracing, no APM. Add what your stack uses.
- No retry logic. If Anthropic returns 5xx, the proxy returns the error. Retries are the client's job.

## Attribution

Part of [byok-patterns](https://github.com/0xelitesystem/byok-patterns).
