import { Router } from "express";
import type { Request, Response, NextFunction } from "express";
import fs from "fs";
import path from "path";
import crypto from "crypto";
import { execFile, execFileSync } from "child_process";
import { promisify } from "util";
import sharp from "sharp";
import { config, sanitizePath, isSymlinkEscapingRoot } from "./config";
import { badRequest, notFound, forbidden, internalError } from "./errors";
import { AppError } from "./errors";

const execFileAsync = promisify(execFile);
const router = Router();

function resolveFfmpeg(): string {
  try {
    return execFileSync("which", ["ffmpeg"], { encoding: "utf8" }).trim();
  } catch {
    return "ffmpeg";
  }
}
const FFMPEG_BIN = resolveFfmpeg();

const THUMB_DIR = path.join(config.rootDir, ".thumbnails");
const THUMB_MAX = 300;

const ALLOWED_IMAGE_EXTS = new Set([".jpg", ".jpeg", ".png", ".webp", ".gif"]);
const ALLOWED_VIDEO_EXTS = new Set([".mp4", ".webm", ".mov", ".mkv", ".avi", ".ogv"]);
const ALLOWED_EXTS = new Set([...ALLOWED_IMAGE_EXTS, ...ALLOWED_VIDEO_EXTS]);

if (!fs.existsSync(THUMB_DIR)) {
  fs.mkdirSync(THUMB_DIR, { recursive: true });
}

let activeGenerations = 0;
const MAX_CONCURRENT = 3;

function getThumbName(fullPath: string, mtime: number): string {
  const hash = crypto
    .createHash("md5")
    .update(fullPath + ":" + mtime)
    .digest("hex")
    .slice(0, 12);
  const base = path.basename(fullPath, path.extname(fullPath));
  const safeName = base.replace(/[^a-zA-Z0-9_-]/g, "_").slice(0, 50);
  return `${safeName}_${hash}_thumb.webp`;
}

async function generateImageThumb(fullPath: string, thumbPath: string): Promise<void> {
  await sharp(fullPath)
    .resize(THUMB_MAX, THUMB_MAX, { fit: "inside", withoutEnlargement: true })
    .webp({ quality: 75 })
    .toFile(thumbPath);
}

async function generateVideoThumb(fullPath: string, thumbPath: string): Promise<void> {
  const tmpJpeg = thumbPath + ".tmp.jpg";
  try {
    await execFileAsync(FFMPEG_BIN, [
      "-ss", "00:00:01",
      "-i", fullPath,
      "-frames:v", "1",
      "-vf", `scale=${THUMB_MAX}:-1`,
      "-q:v", "2",
      "-y",
      tmpJpeg,
    ], { timeout: 15000 });

    await sharp(tmpJpeg)
      .resize(THUMB_MAX, THUMB_MAX, { fit: "inside", withoutEnlargement: true })
      .webp({ quality: 75 })
      .toFile(thumbPath);
  } finally {
    if (fs.existsSync(tmpJpeg)) {
      try { fs.unlinkSync(tmpJpeg); } catch {}
    }
  }
}

router.get("/", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const requestedPath = (req.query.path as string) || "";
    if (!requestedPath) {
      throw badRequest("Path is required");
    }

    const fullPath = sanitizePath(requestedPath);

    if (!fs.existsSync(fullPath)) {
      throw notFound("File not found");
    }

    if (isSymlinkEscapingRoot(fullPath)) {
      throw forbidden("Access denied");
    }

    const ext = path.extname(fullPath).toLowerCase();
    if (!ALLOWED_EXTS.has(ext)) {
      throw badRequest("Not a supported image or video type");
    }

    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) {
      throw badRequest("Cannot thumbnail a directory");
    }

    const thumbName = getThumbName(fullPath, stat.mtimeMs);
    const thumbPath = path.join(THUMB_DIR, thumbName);

    if (fs.existsSync(thumbPath)) {
      res.setHeader("Content-Type", "image/webp");
      res.setHeader("Cache-Control", "public, max-age=86400");
      fs.createReadStream(thumbPath).pipe(res);
      return;
    }

    if (activeGenerations >= MAX_CONCURRENT) {
      throw new AppError(503, "SERVICE_UNAVAILABLE", "Thumbnail generation busy, try again");
    }

    activeGenerations++;
    try {
      const isVideo = ALLOWED_VIDEO_EXTS.has(ext);
      if (isVideo) {
        await generateVideoThumb(fullPath, thumbPath);
      } else {
        await generateImageThumb(fullPath, thumbPath);
      }

      res.setHeader("Content-Type", "image/webp");
      res.setHeader("Cache-Control", "public, max-age=86400");
      fs.createReadStream(thumbPath).pipe(res);
    } catch (genErr) {
      if (fs.existsSync(thumbPath)) {
        try { fs.unlinkSync(thumbPath); } catch {}
      }
      const msg = genErr instanceof Error ? genErr.message : String(genErr);
      throw internalError(`Failed to generate thumbnail: ${msg}`);
    } finally {
      activeGenerations--;
    }
  } catch (err) {
    next(err);
  }
});

export default router;
