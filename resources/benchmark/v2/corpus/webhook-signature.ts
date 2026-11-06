import { createHmac, timingSafeEqual } from "node:crypto";

/**
 * Verifies Northwind webhook signatures (STD-API-006).
 *
 * Header format: X-Northwind-Signature: t=<unix seconds>,v1=<hex digest>
 * The digest is HMAC-SHA256 over `${timestamp}.${rawBody}`.
 */

export const DEFAULT_TOLERANCE_SECONDS = 300;
export const SECRET_ENV_NAME = "NW_WEBHOOK_SECRET";

export type VerifyFailure =
  | "malformed_header"
  | "timestamp_out_of_tolerance"
  | "signature_mismatch";

export interface VerifyResult {
  ok: boolean;
  reason?: VerifyFailure;
}

export interface ParsedHeader {
  timestamp: number;
  signatures: string[];
}

/** Splits `t=...,v1=...` into its parts; returns null when it is unusable. */
export function parseSignatureHeader(header: string): ParsedHeader | null {
  let timestamp: number | null = null;
  const signatures: string[] = [];

  for (const part of header.split(",")) {
    const [key, value] = part.trim().split("=");
    if (key === "t" && value) {
      timestamp = Number.parseInt(value, 10);
    } else if (key === "v1" && value) {
      signatures.push(value);
    }
  }

  if (timestamp === null || Number.isNaN(timestamp) || signatures.length === 0) {
    return null;
  }
  return { timestamp, signatures };
}

export function computeSignature(secret: string, timestamp: number, rawBody: string): string {
  return createHmac("sha256", secret).update(`${timestamp}.${rawBody}`).digest("hex");
}

/** Constant-time comparison; different lengths are rejected without comparing bytes. */
function safeEqualHex(a: string, b: string): boolean {
  const left = Buffer.from(a, "utf8");
  const right = Buffer.from(b, "utf8");
  if (left.length !== right.length) {
    return false;
  }
  return timingSafeEqual(left, right);
}

export function verifyWebhookSignature(
  rawBody: string,
  header: string,
  secret: string,
  nowSeconds: number = Math.floor(Date.now() / 1000),
  toleranceSeconds: number = DEFAULT_TOLERANCE_SECONDS,
): VerifyResult {
  const parsed = parseSignatureHeader(header);
  if (parsed === null) {
    return { ok: false, reason: "malformed_header" };
  }

  // Replay protection: reject old and future-dated requests alike.
  if (Math.abs(nowSeconds - parsed.timestamp) > toleranceSeconds) {
    return { ok: false, reason: "timestamp_out_of_tolerance" };
  }

  const expected = computeSignature(secret, parsed.timestamp, rawBody);
  const matches = parsed.signatures.some((candidate) => safeEqualHex(candidate, expected));
  return matches ? { ok: true } : { ok: false, reason: "signature_mismatch" };
}

/** Reads the secret from the environment; the value itself never lives in code. */
export function loadSecretFromEnv(env: NodeJS.ProcessEnv = process.env): string {
  const secret = env[SECRET_ENV_NAME];
  if (!secret) {
    throw new Error(`Environment variable ${SECRET_ENV_NAME} is not set`);
  }
  return secret;
}
