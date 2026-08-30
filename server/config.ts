import path from "path";
import fs from "fs";

export const config = {
  rootDir: path.resolve(process.env.ROOT_DIR || "./storage"),
  authUser: process.env.AUTH_USER || "admin",
  authPass: process.env.AUTH_PASS || "admin",
  readOnly: process.env.READ_ONLY === "true",
  allowDelete: process.env.ALLOW_DELETE !== "false",
  allowOverwrite: process.env.ALLOW_OVERWRITE !== "false",
  httpsEnabled: process.env.HTTPS_ENABLED === "true",
};

if (!fs.existsSync(config.rootDir)) {
  fs.mkdirSync(config.rootDir, { recursive: true });
}

if (config.authUser === "admin" && config.authPass === "admin") {
  console.warn("\n⚠️  WARNING: Using default credentials (admin/admin). Set AUTH_USER and AUTH_PASS environment variables for production use.\n");
}

export function isSymlinkEscapingRoot(filePath: string): boolean {
  try {
    const lstats = fs.lstatSync(filePath);
    if (lstats.isSymbolicLink()) {
      const realPath = fs.realpathSync(filePath);
      const normalizedRoot = path.resolve(config.rootDir);
      return !realPath.startsWith(normalizedRoot + path.sep) && realPath !== normalizedRoot;
    }
    return false;
  } catch {
    return false;
  }
}

export function sanitizePath(requestedPath: string): string {
  let decoded = decodeURIComponent(requestedPath || "");
  decoded = decoded.replace(/\\/g, "/");
  const segments = decoded.split("/").filter(Boolean);
  const safe: string[] = [];
  for (const seg of segments) {
    if (seg === "." || seg === "..") continue;
    if (seg.includes("\0")) continue;
    safe.push(seg);
  }
  const resolved = path.resolve(config.rootDir, ...safe);
  const normalizedRoot = path.resolve(config.rootDir);
  if (!resolved.startsWith(normalizedRoot + path.sep) && resolved !== normalizedRoot) {
    return normalizedRoot;
  }
  return resolved;
}
