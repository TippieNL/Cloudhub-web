import { Router } from "express";
import type { Request, Response, NextFunction } from "express";
import fs from "fs";
import path from "path";
import multer from "multer";
import archiver from "archiver";
import mime from "mime-types";
import { config, sanitizePath, isSymlinkEscapingRoot } from "./config";
import type { FileItem, ServerConfig } from "@shared/schema";
import { storage } from "./storage";
import { createAdapter } from "./adapters";
import { forbidden, notFound, badRequest, conflict, internalError } from "./errors";

const router = Router();

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 100 * 1024 * 1024, files: 20 },
});

// ----- Helpers -----

function requireWritable() {
  if (config.readOnly) {
    throw forbidden("Server is in read-only mode");
  }
}

function validateUploadFilename(originalName: string): string {
  const safeName = path.basename(originalName);
  if (!safeName || safeName === "." || safeName === "..") {
    throw badRequest(`Invalid filename: ${originalName}`);
  }
  return safeName;
}

function isWithinRoot(filePath: string): boolean {
  const normalizedRoot = path.resolve(config.rootDir);
  return filePath.startsWith(normalizedRoot + path.sep) || filePath === normalizedRoot;
}

function addDirectoryToArchive(
  archive: archiver.Archiver,
  dirPath: string,
  archivePrefix: string,
) {
  for (const entry of fs.readdirSync(dirPath)) {
    const entryPath = path.join(dirPath, entry);
    if (isSymlinkEscapingRoot(entryPath)) continue;

    const entryStats = fs.lstatSync(entryPath);
    const archiveName = archivePrefix + "/" + entry;

    if (entryStats.isDirectory()) {
      addDirectoryToArchive(archive, entryPath, archiveName);
    } else if (entryStats.isFile()) {
      archive.file(entryPath, { name: archiveName });
    }
  }
}

async function uploadToRemoteServer(
  serverId: number,
  uploadedFiles: Express.Multer.File[],
  targetPath: string,
) {
  const server = await storage.getServer(serverId);
  if (!server) {
    throw notFound("Storage server not found");
  }
  if (!server.isActive) {
    throw badRequest("Storage server is not active");
  }

  const adapter = createAdapter(server);
  const savedNames: string[] = [];

  for (const file of uploadedFiles) {
    const safeName = validateUploadFilename(file.originalname);
    const result = await adapter.upload(file.buffer, targetPath, safeName);
    const mimeType = mime.lookup(safeName) || "application/octet-stream";

    await storage.createFileMetadata({
      serverId: server.id,
      filePath: result.remotePath,
      originalName: safeName,
      size: file.size,
      mimeType,
    });

    savedNames.push(safeName);
  }

  return {
    success: true,
    message: `${savedNames.length} file(s) uploaded to ${server.name}`,
    files: savedNames,
    serverId: server.id,
    serverName: server.name,
  };
}

function uploadToLocalStorage(
  uploadedFiles: Express.Multer.File[],
  targetPath: string,
) {
  const targetDir = sanitizePath(targetPath);
  if (!fs.existsSync(targetDir)) {
    fs.mkdirSync(targetDir, { recursive: true });
  }

  const savedNames: string[] = [];

  for (const file of uploadedFiles) {
    const safeName = validateUploadFilename(file.originalname);
    const destination = path.join(targetDir, safeName);

    if (!isWithinRoot(destination)) {
      throw forbidden("Path not allowed");
    }
    if (isSymlinkEscapingRoot(destination)) {
      throw forbidden("Access denied");
    }
    if (fs.existsSync(destination) && !config.allowOverwrite) {
      throw conflict(`File already exists: ${safeName}`);
    }

    fs.writeFileSync(destination, file.buffer);
    savedNames.push(safeName);
  }

  return {
    success: true,
    message: `${savedNames.length} file(s) uploaded successfully`,
    files: savedNames,
  };
}

// ----- Routes -----

router.get("/config", (_req: Request, res: Response) => {
  const serverConfig: ServerConfig = {
    readOnly: config.readOnly,
    allowDelete: config.allowDelete,
    allowOverwrite: config.allowOverwrite,
  };
  res.json(serverConfig);
});

router.get("/list", (req: Request, res: Response, next: NextFunction) => {
  try {
    const requestedPath = (req.query.path as string) || "/";
    const fullPath = sanitizePath(requestedPath);

    if (!fs.existsSync(fullPath)) {
      throw notFound("Directory not found");
    }

    const stat = fs.statSync(fullPath);
    if (!stat.isDirectory()) {
      throw badRequest("Path is not a directory");
    }

    const items: FileItem[] = fs
      .readdirSync(fullPath)
      .filter((name) => !isSymlinkEscapingRoot(path.join(fullPath, name)))
      .map((name) => {
        const entryPath = path.join(fullPath, name);
        let entryStat: fs.Stats;
        try {
          entryStat = fs.statSync(entryPath);
        } catch {
          entryStat = fs.lstatSync(entryPath);
        }
        const relativePath = path.relative(config.rootDir, entryPath);
        const ext = path.extname(name);
        return {
          name,
          path: "/" + relativePath.replace(/\\/g, "/"),
          isDirectory: entryStat.isDirectory(),
          size: entryStat.size,
          modified: entryStat.mtime.toISOString(),
          ...(ext && !entryStat.isDirectory() ? { extension: ext } : {}),
        };
      });

    res.json(items);
  } catch (err) {
    next(err);
  }
});

router.get("/download", (req: Request, res: Response, next: NextFunction) => {
  try {
    const requestedPath = (req.query.path as string) || "";
    const fullPath = sanitizePath(requestedPath);

    if (!fs.existsSync(fullPath)) {
      throw notFound("File not found");
    }
    if (isSymlinkEscapingRoot(fullPath)) {
      throw forbidden("Access denied");
    }

    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) {
      throw badRequest("Cannot download a directory");
    }

    const filename = path.basename(fullPath);
    const mimeType = mime.lookup(fullPath) || "application/octet-stream";
    const safeName = filename.replace(/["\\\r\n]/g, "_");

    res.setHeader("Content-Type", mimeType);
    res.setHeader("Content-Disposition", `attachment; filename="${safeName}"`);
    res.setHeader("Content-Length", stat.size);
    const stream = fs.createReadStream(fullPath);
    stream.on("error", () => {
      if (!res.headersSent) {
        next(internalError("Failed to read file"));
      } else {
        res.destroy();
      }
    });
    stream.pipe(res);
  } catch (err) {
    next(err);
  }
});

router.post("/download-zip", (req: Request, res: Response, next: NextFunction) => {
  try {
    const { files } = req.body as { files: string[] };

    if (!files || !Array.isArray(files) || files.length === 0) {
      throw badRequest("No files specified");
    }

    res.setHeader("Content-Type", "application/zip");
    res.setHeader("Content-Disposition", 'attachment; filename="download.zip"');

    const archive = archiver("zip", { zlib: { level: 9 } });
    archive.on("error", (err) => next(internalError(err.message)));
    archive.pipe(res);

    for (const filePath of files) {
      const fullPath = sanitizePath(filePath);
      if (!fs.existsSync(fullPath) || isSymlinkEscapingRoot(fullPath)) continue;

      const stat = fs.statSync(fullPath);
      const name = path.basename(fullPath);

      if (stat.isDirectory()) {
        addDirectoryToArchive(archive, fullPath, name);
      } else {
        archive.file(fullPath, { name });
      }
    }

    archive.finalize();
  } catch (err) {
    next(err);
  }
});

router.post("/upload", (req: Request, res: Response, next: NextFunction) => {
  try {
    requireWritable();
  } catch (err) {
    return next(err);
  }

  upload.array("files")(req, res, async (err) => {
    if (err) {
      return next(internalError(err.message));
    }

    try {
      const uploadedFiles = req.files as Express.Multer.File[];
      if (!uploadedFiles || uploadedFiles.length === 0) {
        throw badRequest("No files uploaded");
      }

      const targetPath = (req.body && req.body.targetPath) || "/";
      const rawServerId = req.body?.serverId ? parseInt(req.body.serverId, 10) : null;
      const serverId = rawServerId && !isNaN(rawServerId) ? rawServerId : null;

      if (serverId) {
        const result = await uploadToRemoteServer(serverId, uploadedFiles, targetPath);
        res.json(result);
      } else {
        const result = uploadToLocalStorage(uploadedFiles, targetPath);
        res.json(result);
      }
    } catch (uploadErr) {
      next(uploadErr);
    }
  });
});

router.post("/mkdir", (req: Request, res: Response, next: NextFunction) => {
  try {
    requireWritable();

    const { path: dirPath } = req.body as { path: string };
    if (!dirPath) {
      throw badRequest("Path is required");
    }

    const fullPath = sanitizePath(dirPath);
    if (fs.existsSync(fullPath)) {
      throw conflict("Directory already exists");
    }

    fs.mkdirSync(fullPath, { recursive: true });
    res.json({ success: true, message: "Directory created" });
  } catch (err) {
    next(err);
  }
});

router.delete("/delete", (req: Request, res: Response, next: NextFunction) => {
  try {
    requireWritable();

    if (!config.allowDelete) {
      throw forbidden("Delete is not allowed");
    }

    const { path: deletePath } = req.body as { path: string };
    if (!deletePath) {
      throw badRequest("Path is required");
    }

    const fullPath = sanitizePath(deletePath);
    if (!fs.existsSync(fullPath)) {
      throw notFound("File or directory not found");
    }
    if (isSymlinkEscapingRoot(fullPath)) {
      throw forbidden("Access denied");
    }

    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) {
      fs.rmSync(fullPath, { recursive: true, force: true });
    } else {
      fs.unlinkSync(fullPath);
    }

    res.json({ success: true, message: "Deleted successfully" });
  } catch (err) {
    next(err);
  }
});

router.post("/rename", (req: Request, res: Response, next: NextFunction) => {
  try {
    requireWritable();

    const { oldPath, newPath } = req.body as { oldPath: string; newPath: string };
    if (!oldPath || !newPath) {
      throw badRequest("Both oldPath and newPath are required");
    }

    const fullOldPath = sanitizePath(oldPath);
    const fullNewPath = sanitizePath(newPath);

    if (!fs.existsSync(fullOldPath)) {
      throw notFound("Source not found");
    }
    if (isSymlinkEscapingRoot(fullOldPath) || isSymlinkEscapingRoot(fullNewPath)) {
      throw forbidden("Access denied");
    }
    if (fs.existsSync(fullNewPath) && !config.allowOverwrite) {
      throw conflict("Destination already exists");
    }

    const destDir = path.dirname(fullNewPath);
    if (!fs.existsSync(destDir)) {
      fs.mkdirSync(destDir, { recursive: true });
    }

    fs.renameSync(fullOldPath, fullNewPath);
    res.json({ success: true, message: "Renamed successfully" });
  } catch (err) {
    next(err);
  }
});

export default router;
