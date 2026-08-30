import type { Request, Response } from "express";
import fs from "fs";
import path from "path";
import { Builder } from "xml2js";
import mime from "mime-types";
import { config, sanitizePath, isSymlinkEscapingRoot } from "./config";

const xmlBuilder = new Builder({
  xmldec: { version: "1.0", encoding: "UTF-8" },
});

function getWebDAVPath(req: Request): string {
  const url = req.originalUrl.split("?")[0];
  const reqPath = url.replace(/^\/webdav\/?/, "") || "";
  return decodeURIComponent(reqPath);
}

function buildPropResponse(href: string, stat: fs.Stats, isDirectory: boolean): object {
  const props: Record<string, string> = {
    "d:getlastmodified": stat.mtime.toUTCString(),
    "d:creationdate": stat.birthtime.toISOString(),
  };

  if (isDirectory) {
    props["d:resourcetype"] = "";
  } else {
    props["d:getcontentlength"] = String(stat.size);
    props["d:getcontenttype"] = mime.lookup(href) || "application/octet-stream";
  }

  const response: Record<string, any> = {
    "d:href": href,
    "d:propstat": {
      "d:prop": props,
      "d:status": "HTTP/1.1 200 OK",
    },
  };

  if (isDirectory) {
    response["d:propstat"]["d:prop"]["d:resourcetype"] = { "d:collection": "" };
  }

  return response;
}

function handleOptions(_req: Request, res: Response): void {
  res.setHeader("Allow", "OPTIONS, PROPFIND, GET, PUT, DELETE, MKCOL, MOVE");
  res.setHeader("DAV", "1");
  res.setHeader("MS-Author-Via", "DAV");
  res.status(200).end();
}

function handlePropfind(req: Request, res: Response): void {
  const reqPath = getWebDAVPath(req);
  const fullPath = sanitizePath(reqPath);

  if (!fs.existsSync(fullPath)) {
    res.status(404).end();
    return;
  }

  const stat = fs.statSync(fullPath);
  const depth = req.headers.depth || "1";
  const responses: object[] = [];

  const hrefBase = "/webdav/" + path.relative(config.rootDir, fullPath).replace(/\\/g, "/");
  responses.push(buildPropResponse(hrefBase || "/webdav/", stat, stat.isDirectory()));

  if (stat.isDirectory() && depth !== "0") {
    try {
      const entries = fs.readdirSync(fullPath);
      for (const entry of entries) {
        const entryPath = path.join(fullPath, entry);
        if (isSymlinkEscapingRoot(entryPath)) continue;
        try {
          const entryStat = fs.statSync(entryPath);
          const entryHref = hrefBase + (hrefBase.endsWith("/") ? "" : "/") + encodeURIComponent(entry);
          responses.push(buildPropResponse(entryHref, entryStat, entryStat.isDirectory()));
        } catch {
          continue;
        }
      }
    } catch {
      // ignore read errors
    }
  }

  const xml = xmlBuilder.buildObject({
    "d:multistatus": {
      $: { "xmlns:d": "DAV:" },
      "d:response": responses,
    },
  });

  res.setHeader("Content-Type", "application/xml; charset=utf-8");
  res.status(207).send(xml);
}

function handleGet(req: Request, res: Response): void {
  const reqPath = getWebDAVPath(req);
  const fullPath = sanitizePath(reqPath);

  if (!fs.existsSync(fullPath)) {
    res.status(404).end();
    return;
  }

  if (isSymlinkEscapingRoot(fullPath)) {
    res.status(403).end();
    return;
  }

  const stat = fs.statSync(fullPath);
  if (stat.isDirectory()) {
    res.status(405).end();
    return;
  }

  const mimeType = mime.lookup(fullPath) || "application/octet-stream";
  res.setHeader("Content-Type", mimeType);
  res.setHeader("Content-Length", stat.size);
  fs.createReadStream(fullPath).pipe(res);
}

function handlePut(req: Request, res: Response): void {
  if (config.readOnly) {
    res.status(403).end();
    return;
  }

  const reqPath = getWebDAVPath(req);
  const fullPath = sanitizePath(reqPath);
  const exists = fs.existsSync(fullPath);

  if (isSymlinkEscapingRoot(fullPath)) {
    res.status(403).end();
    return;
  }

  if (exists && !config.allowOverwrite) {
    res.status(409).end();
    return;
  }

  const dir = path.dirname(fullPath);
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }

  const writeStream = fs.createWriteStream(fullPath);

  if (req.readable && !req.readableEnded) {
    req.pipe(writeStream);
    writeStream.on("finish", () => {
      res.status(exists ? 204 : 201).end();
    });
    writeStream.on("error", () => {
      res.status(500).end();
    });
  } else {
    writeStream.end(Buffer.alloc(0), () => {
      res.status(exists ? 204 : 201).end();
    });
  }
}

function handleDelete(req: Request, res: Response): void {
  if (config.readOnly) {
    res.status(403).end();
    return;
  }

  if (!config.allowDelete) {
    res.status(403).end();
    return;
  }

  const reqPath = getWebDAVPath(req);
  const fullPath = sanitizePath(reqPath);

  if (!fs.existsSync(fullPath)) {
    res.status(404).end();
    return;
  }

  if (isSymlinkEscapingRoot(fullPath)) {
    res.status(403).end();
    return;
  }

  const stat = fs.statSync(fullPath);
  if (stat.isDirectory()) {
    fs.rmSync(fullPath, { recursive: true, force: true });
  } else {
    fs.unlinkSync(fullPath);
  }

  res.status(204).end();
}

function handleMkcol(req: Request, res: Response): void {
  if (config.readOnly) {
    res.status(403).end();
    return;
  }

  const reqPath = getWebDAVPath(req);
  const fullPath = sanitizePath(reqPath);

  if (fs.existsSync(fullPath)) {
    res.status(405).end();
    return;
  }

  const parent = path.dirname(fullPath);
  if (!fs.existsSync(parent)) {
    res.status(409).end();
    return;
  }

  fs.mkdirSync(fullPath);
  res.status(201).end();
}

function handleMove(req: Request, res: Response): void {
  if (config.readOnly) {
    res.status(403).end();
    return;
  }

  const reqPath = getWebDAVPath(req);
  const fullPath = sanitizePath(reqPath);
  const destination = req.headers.destination as string;

  if (!destination) {
    res.status(400).end();
    return;
  }

  if (!fs.existsSync(fullPath)) {
    res.status(404).end();
    return;
  }

  if (isSymlinkEscapingRoot(fullPath)) {
    res.status(403).end();
    return;
  }

  let destPath: string;
  try {
    const destUrl = new URL(destination, `http://${req.headers.host}`);
    const destRelative = decodeURIComponent(destUrl.pathname).replace(/^\/webdav\/?/, "");
    destPath = sanitizePath(destRelative);
  } catch {
    res.status(400).end();
    return;
  }

  const destExists = fs.existsSync(destPath);
  const overwrite = req.headers.overwrite !== "F";

  if (destExists && !overwrite) {
    res.status(412).end();
    return;
  }

  if (destExists && !config.allowOverwrite) {
    res.status(409).end();
    return;
  }

  if (fs.existsSync(destPath) && isSymlinkEscapingRoot(destPath)) {
    res.status(403).end();
    return;
  }

  const destDir = path.dirname(destPath);
  const normalizedRoot = path.resolve(config.rootDir);
  const destDirReal = fs.existsSync(destDir) ? fs.realpathSync(destDir) : destDir;
  if (!destDirReal.startsWith(normalizedRoot + path.sep) && destDirReal !== normalizedRoot) {
    res.status(403).end();
    return;
  }

  if (!fs.existsSync(destDir)) {
    fs.mkdirSync(destDir, { recursive: true });
  }

  fs.renameSync(fullPath, destPath);
  res.status(destExists ? 204 : 201).end();
}

export function handleWebDAV(req: Request, res: Response): void {
  switch (req.method) {
    case "OPTIONS":
      handleOptions(req, res);
      break;
    case "PROPFIND":
      handlePropfind(req, res);
      break;
    case "GET":
      handleGet(req, res);
      break;
    case "PUT":
      handlePut(req, res);
      break;
    case "DELETE":
      handleDelete(req, res);
      break;
    case "MKCOL":
      handleMkcol(req, res);
      break;
    case "MOVE":
      handleMove(req, res);
      break;
    default:
      res.status(405).end();
  }
}
