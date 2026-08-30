import { Router } from "express";
import type { Request, Response, NextFunction } from "express";
import fs from "fs";
import path from "path";
import crypto from "crypto";
import mime from "mime-types";
import { config, sanitizePath, validatePathContainment } from "./config";
import { badRequest, forbidden, notFound } from "./errors";

const router = Router();

interface MediaTokenPayload {
  path: string;
  exp: number;
}

function getTokenSecret(): string {
  // Development fallback keeps local development friction low. Production is
  // explicitly required to provide MEDIA_TOKEN_SECRET in config.ts.
  return config.mediaTokenSecret || crypto.createHash("sha256").update(config.authPass).digest("hex");
}

function encodeBase64Url(value: string): string {
  return Buffer.from(value, "utf8").toString("base64url");
}

function signPayload(encodedPayload: string): string {
  return crypto.createHmac("sha256", getTokenSecret()).update(encodedPayload).digest("base64url");
}

function createMediaToken(filePath: string): string {
  const payload: MediaTokenPayload = {
    path: filePath,
    exp: Math.floor(Date.now() / 1000) + config.mediaTokenTtlSeconds,
  };
  const encodedPayload = encodeBase64Url(JSON.stringify(payload));
  return `${encodedPayload}.${signPayload(encodedPayload)}`;
}

function verifyMediaToken(token: string): MediaTokenPayload {
  const [encodedPayload, signature] = token.split(".");
  if (!encodedPayload || !signature) throw forbidden("Invalid media token");

  const expected = signPayload(encodedPayload);
  const suppliedBuffer = Buffer.from(signature, "base64url");
  const expectedBuffer = Buffer.from(expected, "base64url");
  if (
    suppliedBuffer.length !== expectedBuffer.length ||
    !crypto.timingSafeEqual(suppliedBuffer, expectedBuffer)
  ) {
    throw forbidden("Invalid media token");
  }

  let payload: MediaTokenPayload;
  try {
    payload = JSON.parse(Buffer.from(encodedPayload, "base64url").toString("utf8"));
  } catch {
    throw forbidden("Invalid media token");
  }

  if (!payload || typeof payload.path !== "string" || !Number.isSafeInteger(payload.exp)) {
    throw forbidden("Invalid media token");
  }
  if (payload.exp < Math.floor(Date.now() / 1000)) {
    throw forbidden("Media token expired");
  }

  return payload;
}

function resolveTokenPath(token: string): string {
  const payload = verifyMediaToken(token);
  const fullPath = sanitizePath(payload.path);
  validatePathContainment(fullPath);
  return fullPath;
}

function parseRangeHeader(rangeHeader: string | undefined, size: number): { start: number; end: number } | null {
  if (!rangeHeader) return null;
  if (!rangeHeader.startsWith("bytes=")) throw badRequest("Invalid Range header");

  const ranges = rangeHeader.slice(6).split(",").map((value) => value.trim()).filter(Boolean);
  if (ranges.length !== 1) throw badRequest("Multiple byte ranges are not supported");

  const [rawStart, rawEnd] = ranges[0].split("-", 2);
  let start: number;
  let end: number;

  if (rawStart === "") {
    const suffixLength = Number(rawEnd);
    if (!Number.isSafeInteger(suffixLength) || suffixLength <= 0) throw badRequest("Invalid Range header");
    start = Math.max(0, size - suffixLength);
    end = size - 1;
  } else {
    start = Number(rawStart);
    end = rawEnd === "" ? size - 1 : Number(rawEnd);
    if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || start < 0 || end < start) {
      throw badRequest("Invalid Range header");
    }
    if (start >= size) {
      return { start: size, end: size - 1 };
    }
    end = Math.min(end, size - 1);
  }

  return { start, end };
}

router.post("/token", (req: Request, res: Response, next: NextFunction) => {
  try {
    const requestedPath = req.body?.path;
    if (typeof requestedPath !== "string" || !requestedPath) {
      throw badRequest("Path is required");
    }

    const fullPath = sanitizePath(requestedPath);
    if (!fs.existsSync(fullPath)) throw notFound("File not found");
    validatePathContainment(fullPath);

    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) throw badRequest("Cannot create a media token for a directory");

    const relativePath = "/" + path.relative(config.rootDir, fullPath).replace(/\\/g, "/");
    const token = createMediaToken(relativePath);
    res.setHeader("Cache-Control", "no-store");
    res.json({
      url: `/media/${encodeURIComponent(token)}`,
      expiresAt: Date.now() + config.mediaTokenTtlSeconds * 1000,
    });
  } catch (err) {
    next(err);
  }
});

export function handleMediaRequest(req: Request, res: Response, next: NextFunction): void {
  try {
    const token = req.params.token;
    if (!token) throw forbidden("Media token required");

    const fullPath = resolveTokenPath(token);
    if (!fs.existsSync(fullPath)) throw notFound("File not found");

    const stat = fs.statSync(fullPath);
    if (!stat.isFile()) throw badRequest("Media target is not a file");
    if (stat.size > Number.MAX_SAFE_INTEGER) throw badRequest("File is too large");

    const mimeType = mime.lookup(fullPath) || "application/octet-stream";
    const filename = path.basename(fullPath).replace(/["\\\r\n]/g, "_");
    const range = parseRangeHeader(req.headers.range, stat.size);

    res.setHeader("Accept-Ranges", "bytes");
    res.setHeader("Content-Type", mimeType);
    const isDownload = req.query.download === "1";
    res.setHeader("Content-Disposition", `${isDownload ? "attachment" : "inline"}; filename="${filename}"`);
    res.setHeader("Cache-Control", isDownload ? "private, no-store" : "private, max-age=60");
    res.setHeader("X-Content-Type-Options", "nosniff");

    if (range && range.start >= stat.size) {
      res.status(416).setHeader("Content-Range", `bytes */${stat.size}`).end();
      return;
    }

    if (!range) {
      res.setHeader("Content-Length", stat.size);
      if (req.method === "HEAD") {
        res.status(200).end();
        return;
      }
      const stream = fs.createReadStream(fullPath);
      stream.on("error", () => res.destroy());
      stream.pipe(res);
      return;
    }

    const contentLength = range.end - range.start + 1;
    res.status(206);
    res.setHeader("Content-Range", `bytes ${range.start}-${range.end}/${stat.size}`);
    res.setHeader("Content-Length", contentLength);
    if (req.method === "HEAD") {
      res.end();
      return;
    }

    const stream = fs.createReadStream(fullPath, { start: range.start, end: range.end });
    stream.on("error", () => res.destroy());
    stream.pipe(res);
  } catch (err) {
    next(err);
  }
}

export default router;
