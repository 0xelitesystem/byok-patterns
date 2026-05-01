/**
 * BYOK proxy server.
 *
 * Forwards user-supplied API keys to Anthropic. Keys flow through in transit
 * but are never stored, never logged, never persisted.
 *
 * Zero dependencies. Runs on Node 18+ or Bun.
 *
 * Start:  node server.js
 * Override: PORT=8080 RATE_LIMIT_PER_MIN=30 ALLOWED_ORIGINS=https://yoursite.com node server.js
 */

"use strict";

const http = require("node:http");
const crypto = require("node:crypto");

// ---------------------------------------------------------------------------
// Configuration. All overridable via environment variables.
// ---------------------------------------------------------------------------
const PORT = parseInt(process.env.PORT, 10) || 3000;
const HOST = process.env.HOST || "0.0.0.0";
const RATE_LIMIT_PER_MIN = parseInt(process.env.RATE_LIMIT_PER_MIN, 10) || 60;
const RATE_WINDOW_MS = 60 * 1000;
const MAX_BODY_BYTES = parseInt(process.env.MAX_BODY_BYTES, 10) || 1024 * 1024; // 1 MB
const REQUEST_TIMEOUT_MS = parseInt(process.env.REQUEST_TIMEOUT_MS, 10) || 60000;

const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || "*")
  .split(",")
  .map(s => s.trim())
  .filter(Boolean);

const ANTHROPIC_BASE = "https://api.anthropic.com/v1/messages";
const ANTHROPIC_VERSION = "2023-06-01";

// ---------------------------------------------------------------------------
// In-memory rate limiter.
// Keyed off a SHA-256 hash of the API key, never the key itself. The map
// holds short-lived counters; entries expire after the rate window.
//
// For multi-instance deployments, replace this section with Redis. The
// public functions (checkAndIncrement, periodic cleanup) stay the same;
// only the storage swaps.
// ---------------------------------------------------------------------------

const rateLimitMap = new Map(); // hashedKey => { count, windowStart }

function hashKey(apiKey) {
  return crypto.createHash("sha256").update(apiKey).digest("hex");
}

function checkAndIncrement(hashedKey) {
  const now = Date.now();
  const entry = rateLimitMap.get(hashedKey);
  if (!entry || now - entry.windowStart >= RATE_WINDOW_MS) {
    rateLimitMap.set(hashedKey, { count: 1, windowStart: now });
    return { allowed: true, remaining: RATE_LIMIT_PER_MIN - 1, resetMs: RATE_WINDOW_MS };
  }
  if (entry.count >= RATE_LIMIT_PER_MIN) {
    return { allowed: false, remaining: 0, resetMs: RATE_WINDOW_MS - (now - entry.windowStart) };
  }
  entry.count += 1;
  return { allowed: true, remaining: RATE_LIMIT_PER_MIN - entry.count, resetMs: RATE_WINDOW_MS - (now - entry.windowStart) };
}

// Periodic cleanup so the map doesn't grow unbounded.
setInterval(() => {
  const now = Date.now();
  for (const [k, v] of rateLimitMap) {
    if (now - v.windowStart >= RATE_WINDOW_MS) rateLimitMap.delete(k);
  }
}, RATE_WINDOW_MS).unref();

// ---------------------------------------------------------------------------
// Logging. Structured, redacted, no key values, no full bodies.
// ---------------------------------------------------------------------------

function log(level, msg, meta = {}) {
  const safe = { ...meta };
  // Defensive redaction: ensure no key field ever leaks even if a future
  // edit accidentally passes one in.
  for (const k of Object.keys(safe)) {
    if (/key|token|secret|authorization/i.test(k)) safe[k] = "[REDACTED]";
  }
  const line = JSON.stringify({ ts: new Date().toISOString(), level, msg, ...safe });
  if (level === "error") process.stderr.write(line + "\n");
  else process.stdout.write(line + "\n");
}

// ---------------------------------------------------------------------------
// CORS handling.
// ---------------------------------------------------------------------------

function getAllowedOrigin(reqOrigin) {
  if (ALLOWED_ORIGINS.includes("*")) return "*";
  if (reqOrigin && ALLOWED_ORIGINS.includes(reqOrigin)) return reqOrigin;
  return null;
}

function setCorsHeaders(res, reqOrigin) {
  const allowed = getAllowedOrigin(reqOrigin);
  if (!allowed) return; // No CORS headers; browser will block.
  res.setHeader("Access-Control-Allow-Origin", allowed);
  res.setHeader("Vary", "Origin");
  res.setHeader("Access-Control-Allow-Methods", "POST, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type, x-user-api-key");
  res.setHeader("Access-Control-Max-Age", "86400");
}

// ---------------------------------------------------------------------------
// JSON helpers.
// ---------------------------------------------------------------------------

function sendJson(res, status, payload) {
  res.statusCode = status;
  res.setHeader("Content-Type", "application/json; charset=utf-8");
  res.end(JSON.stringify(payload));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let total = 0;
    const chunks = [];
    req.on("data", chunk => {
      total += chunk.length;
      if (total > MAX_BODY_BYTES) {
        req.destroy();
        reject(new Error("body_too_large"));
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}

// ---------------------------------------------------------------------------
// Forwarder. Sends the validated request to Anthropic with the user's key.
// ---------------------------------------------------------------------------

async function forwardToAnthropic(userApiKey, requestBody) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    const response = await fetch(ANTHROPIC_BASE, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-api-key": userApiKey,
        "anthropic-version": ANTHROPIC_VERSION
      },
      body: requestBody,
      signal: controller.signal
    });
    const text = await response.text();
    return { status: response.status, body: text };
  } finally {
    clearTimeout(timeoutId);
  }
}

// ---------------------------------------------------------------------------
// Request handler.
// ---------------------------------------------------------------------------

async function handleRequest(req, res) {
  const startedAt = Date.now();
  const url = new URL(req.url, `http://${req.headers.host || "localhost"}`);
  const reqOrigin = req.headers.origin || null;

  setCorsHeaders(res, reqOrigin);

  // Preflight.
  if (req.method === "OPTIONS") {
    res.statusCode = 204;
    res.end();
    return;
  }

  // Health check.
  if (req.method === "GET" && url.pathname === "/healthz") {
    sendJson(res, 200, { ok: true });
    return;
  }

  // Only one route handles real traffic.
  if (req.method !== "POST" || url.pathname !== "/api/messages") {
    sendJson(res, 404, { error: { type: "not_found", message: "Unknown route." } });
    return;
  }

  // Pull the user's API key from the header. Strict validation up front.
  const userApiKey = req.headers["x-user-api-key"];
  if (!userApiKey || typeof userApiKey !== "string") {
    sendJson(res, 400, { error: { type: "missing_key", message: "Missing x-user-api-key header." } });
    return;
  }
  if (!userApiKey.startsWith("sk-ant-")) {
    sendJson(res, 400, { error: { type: "bad_key_format", message: 'API key must start with "sk-ant-".' } });
    return;
  }

  // Rate limit BEFORE reading body, so abusers can't waste bandwidth.
  const hashed = hashKey(userApiKey);
  const rate = checkAndIncrement(hashed);
  res.setHeader("X-RateLimit-Limit", String(RATE_LIMIT_PER_MIN));
  res.setHeader("X-RateLimit-Remaining", String(rate.remaining));
  res.setHeader("X-RateLimit-Reset", String(Math.ceil(rate.resetMs / 1000)));
  if (!rate.allowed) {
    sendJson(res, 429, {
      error: { type: "rate_limited", message: `Rate limit exceeded. Try again in ${Math.ceil(rate.resetMs / 1000)} seconds.` }
    });
    log("warn", "rate_limited", { keyHash: hashed.slice(0, 8) });
    return;
  }

  // Read and validate the request body.
  let bodyText;
  try {
    bodyText = await readBody(req);
  } catch (err) {
    if (err.message === "body_too_large") {
      sendJson(res, 413, { error: { type: "payload_too_large", message: "Request body exceeds limit." } });
      return;
    }
    sendJson(res, 400, { error: { type: "bad_body", message: "Could not read request body." } });
    return;
  }

  let parsed;
  try {
    parsed = JSON.parse(bodyText);
  } catch {
    sendJson(res, 400, { error: { type: "bad_json", message: "Request body is not valid JSON." } });
    return;
  }

  if (!parsed || typeof parsed !== "object" || !Array.isArray(parsed.messages) || !parsed.model) {
    sendJson(res, 400, { error: { type: "bad_shape", message: "Request must include model and messages array." } });
    return;
  }

  // Forward to Anthropic.
  let upstream;
  try {
    upstream = await forwardToAnthropic(userApiKey, bodyText);
  } catch (err) {
    if (err.name === "AbortError") {
      sendJson(res, 504, { error: { type: "timeout", message: "Upstream request timed out." } });
      log("error", "upstream_timeout", { keyHash: hashed.slice(0, 8) });
      return;
    }
    sendJson(res, 502, { error: { type: "upstream_error", message: "Failed to reach Anthropic." } });
    log("error", "upstream_error", { keyHash: hashed.slice(0, 8), err: err.message });
    return;
  }

  // Pass the upstream response straight through.
  res.statusCode = upstream.status;
  res.setHeader("Content-Type", "application/json; charset=utf-8");
  res.end(upstream.body);

  log("info", "proxied", {
    keyHash: hashed.slice(0, 8), // first 8 chars of hash for correlation
    status: upstream.status,
    latencyMs: Date.now() - startedAt
  });
}

// ---------------------------------------------------------------------------
// Server bootstrap.
// ---------------------------------------------------------------------------

const server = http.createServer((req, res) => {
  handleRequest(req, res).catch(err => {
    log("error", "unhandled", { err: err.message });
    if (!res.headersSent) {
      sendJson(res, 500, { error: { type: "internal", message: "Internal server error." } });
    }
  });
});

server.listen(PORT, HOST, () => {
  log("info", "started", {
    port: PORT,
    host: HOST,
    rateLimitPerMin: RATE_LIMIT_PER_MIN,
    allowedOrigins: ALLOWED_ORIGINS,
    maxBodyBytes: MAX_BODY_BYTES
  });
});

// Graceful shutdown.
function shutdown(signal) {
  log("info", "shutdown", { signal });
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(1), 5000).unref();
}
process.on("SIGINT", () => shutdown("SIGINT"));
process.on("SIGTERM", () => shutdown("SIGTERM"));
