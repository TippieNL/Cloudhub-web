import { Router } from "express";
import type { Request, Response, NextFunction } from "express";
import fs from "fs";
import path from "path";
import crypto from "crypto";
import mime from "mime-types";
import { config, sanitizePath, isSymlinkEscapingRoot } from "./config";
import { badRequest, notFound, forbidden } from "./errors";

const router = Router();

interface ShareEntry {
  filePath: string;
  token: string;
  createdAt: number;
  expiresAt: number | null;
}

const shareStore = new Map<string, ShareEntry>();

const DEFAULT_EXPIRY_HOURS = parseInt(process.env.SHARE_EXPIRY_HOURS || "0", 10);

function generateToken(): string {
  return crypto.randomBytes(32).toString("base64url");
}

function cleanExpired() {
  const now = Date.now();
  const tokens = Array.from(shareStore.keys());
  for (const token of tokens) {
    const entry = shareStore.get(token);
    if (entry && entry.expiresAt && entry.expiresAt < now) {
      shareStore.delete(token);
    }
  }
}

setInterval(cleanExpired, 60 * 60 * 1000);

router.post("/create", (req: Request, res: Response, next: NextFunction) => {
  try {
    const { filePath, expiresInHours } = req.body as {
      filePath: string;
      expiresInHours?: number;
    };

    if (!filePath) {
      throw badRequest("filePath is required");
    }

    const fullPath = sanitizePath(filePath);

    if (!fs.existsSync(fullPath)) {
      throw notFound("File not found");
    }

    if (isSymlinkEscapingRoot(fullPath)) {
      throw forbidden("Access denied");
    }

    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) {
      throw badRequest("Cannot share a directory");
    }

    const relativePath = "/" + path.relative(config.rootDir, fullPath).replace(/\\/g, "/");

    for (const entry of Array.from(shareStore.values())) {
      if (entry.filePath === relativePath) {
        if (!entry.expiresAt || entry.expiresAt > Date.now()) {
          const protocol = String(req.headers["x-forwarded-proto"] || (config.httpsEnabled ? "https" : "http"));
          const host = String(req.headers.host || "localhost");
          res.json({
            token: entry.token,
            url: `${protocol}://${host}/share/${entry.token}`,
            expiresAt: entry.expiresAt ? new Date(entry.expiresAt).toISOString() : null,
          });
          return;
        }
        shareStore.delete(entry.token);
      }
    }

    const token = generateToken();
    const hours = expiresInHours ?? DEFAULT_EXPIRY_HOURS;
    const expiresAt = hours > 0 ? Date.now() + hours * 60 * 60 * 1000 : null;

    shareStore.set(token, {
      filePath: relativePath,
      token,
      createdAt: Date.now(),
      expiresAt,
    });

    const protocol = String(req.headers["x-forwarded-proto"] || (config.httpsEnabled ? "https" : "http"));
    const host = String(req.headers.host || "localhost");

    res.json({
      token,
      url: `${protocol}://${host}/share/${token}`,
      expiresAt: expiresAt ? new Date(expiresAt).toISOString() : null,
    });
  } catch (err) {
    next(err);
  }
});

router.delete("/revoke", (req: Request, res: Response, next: NextFunction) => {
  try {
    const { token } = req.body as { token: string };
    if (!token) {
      throw badRequest("Token is required");
    }
    const deleted = shareStore.delete(token);
    if (deleted) {
      res.json({ success: true, message: "Share link revoked" });
    } else {
      throw notFound("Token not found");
    }
  } catch (err) {
    next(err);
  }
});

router.get("/list", (_req: Request, res: Response, next: NextFunction) => {
  try {
    cleanExpired();
    const shares = Array.from(shareStore.values()).map((e) => ({
      token: e.token,
      filePath: e.filePath,
      createdAt: new Date(e.createdAt).toISOString(),
      expiresAt: e.expiresAt ? new Date(e.expiresAt).toISOString() : null,
    }));
    res.json(shares);
  } catch (err) {
    next(err);
  }
});

export function handlePublicShare(req: Request, res: Response) {
  const { token } = req.params;
  if (!token || token.length < 20) {
    res.status(400).send("Invalid share link");
    return;
  }

  const entry = shareStore.get(token);
  if (!entry) {
    res.status(404).send("Share link not found or expired");
    return;
  }

  if (entry.expiresAt && entry.expiresAt < Date.now()) {
    shareStore.delete(token);
    res.status(410).send("Share link has expired");
    return;
  }

  const fullPath = sanitizePath(entry.filePath);

  if (!fs.existsSync(fullPath)) {
    shareStore.delete(token);
    res.status(404).send("File no longer exists");
    return;
  }

  if (isSymlinkEscapingRoot(fullPath)) {
    res.status(403).send("Access denied");
    return;
  }

  const stat = fs.statSync(fullPath);
  if (stat.isDirectory()) {
    res.status(400).send("Cannot share a directory");
    return;
  }

  const filename = path.basename(fullPath);
  const mimeType = mime.lookup(fullPath) || "application/octet-stream";
  const safeName = filename.replace(/["\\\r\n]/g, "_");

  const inlineTypes = [
    "image/", "video/", "audio/",
    "application/pdf", "text/plain", "text/html",
  ];
  const disposition = inlineTypes.some((t) => mimeType.startsWith(t)) ? "inline" : "attachment";

  res.setHeader("Content-Type", mimeType);
  res.setHeader("Content-Disposition", `${disposition}; filename="${safeName}"`);
  res.setHeader("Content-Length", stat.size);
  res.setHeader("Cache-Control", "no-store");

  fs.createReadStream(fullPath).pipe(res);
}

export default router;
