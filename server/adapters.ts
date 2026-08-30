import fs from "fs";
import path from "path";
import { Client } from "basic-ftp";
import { Client as SSHClient, type SFTPWrapper } from "ssh2";
import { Readable } from "stream";
import type { StorageServer } from "@shared/schema";

// ----- Shared types -----

export interface RemoteFileItem {
  name: string;
  path: string;
  isDirectory: boolean;
  size: number;
  modified: string;
  extension?: string;
}

export interface StorageAdapter {
  testConnection(): Promise<{ success: boolean; message: string }>;
  upload(buffer: Buffer, targetPath: string, filename: string): Promise<{ success: boolean; remotePath: string }>;
  deleteFile(filePath: string): Promise<{ success: boolean }>;
  getPublicUrl(filePath: string): string | null;
  ensureDirectory(dirPath: string): Promise<void>;
  listFiles(remotePath: string): Promise<RemoteFileItem[]>;
}

// ----- Shared helpers -----

type ServerConfig = Record<string, any>;

function getConfig(server: StorageServer): ServerConfig {
  return (server.config as ServerConfig) || {};
}

/** Directories first, then alphabetical by name. */
function sortFileItems(items: RemoteFileItem[]): RemoteFileItem[] {
  return items.sort((a, b) => {
    if (a.isDirectory !== b.isDirectory) return a.isDirectory ? -1 : 1;
    return a.name.localeCompare(b.name);
  });
}

/** Build a public URL from a baseUrl config value, or return null. */
function buildPublicUrl(config: ServerConfig, filePath: string): string | null {
  if (!config.baseUrl) return null;
  return config.baseUrl + "/" + filePath.replace(/^\//, "");
}

/**
 * Strip dangerous path segments (., .., null bytes) and return
 * an array of safe path components.
 */
function sanitizePathSegments(rawPath: string): string[] {
  return rawPath
    .replace(/\\/g, "/")
    .split("/")
    .filter(Boolean)
    .filter(seg => seg !== "." && seg !== ".." && !seg.includes("\0"));
}

/**
 * Join a base path with a relative sub-path using POSIX separators.
 * If relativePath is empty, returns basePath itself.
 */
function resolveRemotePath(basePath: string, relativePath: string): string {
  return relativePath ? path.posix.join(basePath, relativePath) : basePath;
}

/** Derive the file extension (lowercase, with leading dot) or undefined for directories. */
function fileExtension(filename: string, isDirectory: boolean): string | undefined {
  if (isDirectory) return undefined;
  const ext = path.extname(filename).toLowerCase();
  return ext || undefined;
}

// =====================================================================
// Local Storage Adapter
// =====================================================================

export class LocalStorageAdapter implements StorageAdapter {
  private basePath: string;
  private config: ServerConfig;

  constructor(server: StorageServer) {
    this.config = getConfig(server);
    this.basePath = path.resolve(this.config.basePath || ".");
  }

  /**
   * Resolve a relative path against the base directory, stripping any
   * traversal segments and verifying the result stays within basePath.
   */
  private resolveAndContain(relativePath: string): string {
    const safeSegments = sanitizePathSegments(relativePath);
    const resolved = path.resolve(this.basePath, ...safeSegments);

    if (!resolved.startsWith(this.basePath + path.sep) && resolved !== this.basePath) {
      throw new Error("Path not allowed: outside base directory");
    }
    return resolved;
  }

  async testConnection(): Promise<{ success: boolean; message: string }> {
    try {
      fs.accessSync(this.basePath, fs.constants.W_OK);
      return { success: true, message: `Local storage path "${this.basePath}" is accessible and writable` };
    } catch (err: any) {
      return { success: false, message: `Local storage path is not accessible: ${err.message}` };
    }
  }

  async upload(buffer: Buffer, targetPath: string, filename: string): Promise<{ success: boolean; remotePath: string }> {
    const safeName = path.basename(filename);
    if (!safeName || safeName === "." || safeName === "..") {
      throw new Error(`Invalid filename: ${filename}`);
    }

    const directory = this.resolveAndContain(targetPath);
    fs.mkdirSync(directory, { recursive: true });

    const destination = path.join(directory, safeName);
    if (!destination.startsWith(this.basePath + path.sep)) {
      throw new Error("Path not allowed");
    }

    fs.writeFileSync(destination, buffer);

    const cleanSegments = sanitizePathSegments(targetPath);
    const remotePath = [...cleanSegments, safeName].join("/");
    return { success: true, remotePath };
  }

  async deleteFile(filePath: string): Promise<{ success: boolean }> {
    const fullPath = this.resolveAndContain(filePath);
    fs.unlinkSync(fullPath);
    return { success: true };
  }

  getPublicUrl(filePath: string): string | null {
    return buildPublicUrl(this.config, filePath);
  }

  async ensureDirectory(dirPath: string): Promise<void> {
    const fullPath = this.resolveAndContain(dirPath);
    fs.mkdirSync(fullPath, { recursive: true });
  }

  async listFiles(remotePath: string): Promise<RemoteFileItem[]> {
    const fullPath = this.resolveAndContain(remotePath);
    const entries = fs.readdirSync(fullPath, { withFileTypes: true });
    const items: RemoteFileItem[] = [];

    for (const entry of entries) {
      if (entry.name.startsWith(".")) continue;

      try {
        const stats = fs.statSync(path.join(fullPath, entry.name));
        const isDir = entry.isDirectory();

        items.push({
          name: entry.name,
          path: remotePath ? path.posix.join(remotePath, entry.name) : entry.name,
          isDirectory: isDir,
          size: isDir ? 0 : stats.size,
          modified: stats.mtime.toISOString(),
          extension: fileExtension(entry.name, isDir),
        });
      } catch {
        continue;
      }
    }

    return sortFileItems(items);
  }
}

// =====================================================================
// FTP Storage Adapter
// =====================================================================

export class FtpStorageAdapter implements StorageAdapter {
  private config: ServerConfig;

  constructor(server: StorageServer) {
    this.config = getConfig(server);
  }

  private get basePath(): string {
    return this.config.basePath || "/";
  }

  private async connect(): Promise<Client> {
    const client = new Client();
    await client.access({
      host: this.config.host,
      port: this.config.port || 21,
      user: this.config.username,
      password: this.config.password,
      secure: this.config.secure || false,
    });
    return client;
  }

  /**
   * Run a callback with a connected FTP client, ensuring the connection
   * is always closed afterward.
   */
  private async withClient<T>(operation: (client: Client) => Promise<T>): Promise<T> {
    const client = await this.connect();
    try {
      return await operation(client);
    } finally {
      client.close();
    }
  }

  async testConnection(): Promise<{ success: boolean; message: string }> {
    try {
      await this.withClient(client => client.list("/"));
      return { success: true, message: "FTP connection successful" };
    } catch (err: any) {
      return { success: false, message: `FTP connection failed: ${err.message}` };
    }
  }

  async upload(buffer: Buffer, targetPath: string, filename: string): Promise<{ success: boolean; remotePath: string }> {
    return this.withClient(async (client) => {
      const remoteDir = path.posix.join(this.basePath, targetPath);
      await client.ensureDir(remoteDir);

      const fullRemotePath = path.posix.join(remoteDir, filename);
      await client.uploadFrom(Readable.from(buffer), fullRemotePath);

      return { success: true, remotePath: path.posix.join(targetPath, filename) };
    });
  }

  async deleteFile(filePath: string): Promise<{ success: boolean }> {
    return this.withClient(async (client) => {
      const fullPath = path.posix.join(this.basePath, filePath);
      await client.remove(fullPath);
      return { success: true };
    });
  }

  getPublicUrl(filePath: string): string | null {
    return buildPublicUrl(this.config, filePath);
  }

  async ensureDirectory(dirPath: string): Promise<void> {
    await this.withClient(async (client) => {
      const fullPath = path.posix.join(this.basePath, dirPath);
      await client.ensureDir(fullPath);
    });
  }

  async listFiles(remotePath: string): Promise<RemoteFileItem[]> {
    return this.withClient(async (client) => {
      const fullPath = resolveRemotePath(this.basePath, remotePath);
      const entries = await client.list(fullPath);
      const items: RemoteFileItem[] = [];

      for (const entry of entries) {
        if (entry.name === "." || entry.name === ".." || entry.name.startsWith(".")) continue;

        const isDir = entry.isDirectory;
        items.push({
          name: entry.name,
          path: remotePath ? path.posix.join(remotePath, entry.name) : entry.name,
          isDirectory: isDir,
          size: isDir ? 0 : entry.size,
          modified: entry.modifiedAt ? entry.modifiedAt.toISOString() : new Date().toISOString(),
          extension: fileExtension(entry.name, isDir),
        });
      }

      return sortFileItems(items);
    });
  }
}

// =====================================================================
// SFTP Storage Adapter
// =====================================================================

export class SftpStorageAdapter implements StorageAdapter {
  private config: ServerConfig;

  constructor(server: StorageServer) {
    this.config = getConfig(server);
  }

  private get basePath(): string {
    return this.config.basePath || "/";
  }

  private connect(): Promise<SSHClient> {
    return new Promise((resolve, reject) => {
      const conn = new SSHClient();
      conn.on("ready", () => resolve(conn));
      conn.on("error", (err: Error) => reject(err));

      const connectOpts: any = {
        host: this.config.host,
        port: this.config.port || 22,
        username: this.config.username,
      };
      if (this.config.privateKey) {
        connectOpts.privateKey = this.config.privateKey;
      } else if (this.config.password) {
        connectOpts.password = this.config.password;
      }
      conn.connect(connectOpts);
    });
  }

  /**
   * Run a callback with an SSH connection, ensuring the connection
   * is always closed afterward.
   */
  private async withConnection<T>(operation: (conn: SSHClient) => Promise<T>): Promise<T> {
    const conn = await this.connect();
    try {
      return await operation(conn);
    } finally {
      conn.end();
    }
  }

  /** Open an SFTP session from an existing SSH connection. */
  private openSftp(conn: SSHClient): Promise<SFTPWrapper> {
    return new Promise((resolve, reject) => {
      conn.sftp((err, sftp) => (err ? reject(err) : resolve(sftp)));
    });
  }

  /** Recursively create directories over SFTP (like mkdir -p). */
  private mkdirRecursive(sftp: SFTPWrapper, targetDir: string): Promise<void> {
    return new Promise((resolve, reject) => {
      const doMkdir = (dir: string, cb: (err?: Error) => void) => {
        sftp.stat(dir, (statErr) => {
          if (!statErr) return cb();
          const parent = path.posix.dirname(dir);
          doMkdir(parent, (parentErr) => {
            if (parentErr) return cb(parentErr);
            sftp.mkdir(dir, (mkdirErr) => {
              // code 4 = directory already exists (race condition)
              if (mkdirErr && (mkdirErr as any).code !== 4) return cb(mkdirErr);
              cb();
            });
          });
        });
      };
      doMkdir(targetDir, (err) => (err ? reject(err) : resolve()));
    });
  }

  async testConnection(): Promise<{ success: boolean; message: string }> {
    try {
      await this.withConnection((conn) =>
        new Promise<void>((resolve, reject) => {
          conn.exec("echo ok", (err, stream) => {
            if (err) return reject(err);
            stream.on("close", () => resolve());
            stream.on("data", () => {});
            stream.stderr.on("data", () => {});
          });
        })
      );
      return { success: true, message: "SFTP connection successful" };
    } catch (err: any) {
      return { success: false, message: `SFTP connection failed: ${err.message}` };
    }
  }

  async upload(buffer: Buffer, targetPath: string, filename: string): Promise<{ success: boolean; remotePath: string }> {
    return this.withConnection(async (conn) => {
      const sftp = await this.openSftp(conn);
      const remoteDir = path.posix.join(this.basePath, targetPath);
      const remotePath = path.posix.join(remoteDir, filename);

      await this.mkdirRecursive(sftp, remoteDir);

      await new Promise<void>((resolve, reject) => {
        const writeStream = sftp.createWriteStream(remotePath);
        writeStream.on("close", () => resolve());
        writeStream.on("error", (err: Error) => reject(err));
        writeStream.end(buffer);
      });

      return { success: true, remotePath: path.posix.join(targetPath, filename) };
    });
  }

  async deleteFile(filePath: string): Promise<{ success: boolean }> {
    return this.withConnection(async (conn) => {
      const sftp = await this.openSftp(conn);
      const fullPath = path.posix.join(this.basePath, filePath);

      await new Promise<void>((resolve, reject) => {
        sftp.unlink(fullPath, (err) => (err ? reject(err) : resolve()));
      });
      return { success: true };
    });
  }

  getPublicUrl(filePath: string): string | null {
    return buildPublicUrl(this.config, filePath);
  }

  async ensureDirectory(dirPath: string): Promise<void> {
    await this.withConnection(async (conn) => {
      const sftp = await this.openSftp(conn);
      const fullPath = path.posix.join(this.basePath, dirPath);
      await this.mkdirRecursive(sftp, fullPath);
    });
  }

  async listFiles(remotePath: string): Promise<RemoteFileItem[]> {
    return this.withConnection(async (conn) => {
      const sftp = await this.openSftp(conn);
      const fullPath = resolveRemotePath(this.basePath, remotePath);

      const entries = await new Promise<any[]>((resolve, reject) => {
        sftp.readdir(fullPath, (err, list) => (err ? reject(err) : resolve(list)));
      });

      const items: RemoteFileItem[] = [];
      for (const entry of entries) {
        if (entry.filename === "." || entry.filename === ".." || entry.filename.startsWith(".")) continue;

        const isDir = !!(entry.attrs.mode && (entry.attrs.mode & 0o40000));
        items.push({
          name: entry.filename,
          path: remotePath ? path.posix.join(remotePath, entry.filename) : entry.filename,
          isDirectory: isDir,
          size: isDir ? 0 : (entry.attrs.size || 0),
          modified: entry.attrs.mtime
            ? new Date(entry.attrs.mtime * 1000).toISOString()
            : new Date().toISOString(),
          extension: fileExtension(entry.filename, isDir),
        });
      }

      return sortFileItems(items);
    });
  }
}

// =====================================================================
// SMB Storage Adapter
// =====================================================================

export class SmbStorageAdapter implements StorageAdapter {
  private config: ServerConfig;

  constructor(server: StorageServer) {
    this.config = getConfig(server);
  }

  private get basePath(): string {
    return this.config.basePath || "/";
  }

  private async createClient(): Promise<any> {
    const SMB2 = (await import("@marsaud/smb2")).default;
    return new SMB2({
      share: `\\\\${this.config.host}\\${this.config.share}`,
      port: this.config.port || 445,
      username: this.config.username || "",
      password: this.config.password || "",
      domain: this.config.domain || "",
    });
  }

  private toSmbPath(relativePath: string): string {
    const safeSegments = sanitizePathSegments(relativePath);
    const base = sanitizePathSegments(this.basePath);
    return [...base, ...safeSegments].join("\\") || "";
  }

  async testConnection(): Promise<{ success: boolean; message: string }> {
    let client: any;
    try {
      client = await this.createClient();
      await client.readdir(this.toSmbPath("") || ".");
      return { success: true, message: "SMB connection successful" };
    } catch (err: any) {
      return { success: false, message: `SMB connection failed: ${err.message}` };
    } finally {
      if (client) {
        try { await client.disconnect(); } catch {}
      }
    }
  }

  private async mkdirRecursive(client: any, smbPath: string): Promise<void> {
    if (!smbPath) return;
    const segments = smbPath.split("\\");
    let current = "";
    for (const seg of segments) {
      current = current ? `${current}\\${seg}` : seg;
      try { await client.mkdir(current); } catch {}
    }
  }

  async upload(buffer: Buffer, targetPath: string, filename: string): Promise<{ success: boolean; remotePath: string }> {
    const safeName = path.basename(filename);
    if (!safeName || safeName === "." || safeName === "..") {
      throw new Error(`Invalid filename: ${filename}`);
    }
    let client: any;
    try {
      client = await this.createClient();
      const dirPath = this.toSmbPath(targetPath);
      await this.mkdirRecursive(client, dirPath);
      const fullPath = dirPath ? `${dirPath}\\${safeName}` : safeName;
      await client.writeFile(fullPath, buffer);
      return { success: true, remotePath: path.posix.join(targetPath, safeName) };
    } finally {
      if (client) {
        try { await client.disconnect(); } catch {}
      }
    }
  }

  async deleteFile(filePath: string): Promise<{ success: boolean }> {
    let client: any;
    try {
      client = await this.createClient();
      const smbPath = this.toSmbPath(filePath);
      await client.unlink(smbPath);
      return { success: true };
    } finally {
      if (client) {
        try { await client.disconnect(); } catch {}
      }
    }
  }

  getPublicUrl(filePath: string): string | null {
    return buildPublicUrl(this.config, filePath);
  }

  async ensureDirectory(dirPath: string): Promise<void> {
    let client: any;
    try {
      client = await this.createClient();
      await this.mkdirRecursive(client, this.toSmbPath(dirPath));
    } finally {
      if (client) {
        try { await client.disconnect(); } catch {}
      }
    }
  }

  async listFiles(remotePath: string): Promise<RemoteFileItem[]> {
    let client: any;
    try {
      client = await this.createClient();
      const smbPath = this.toSmbPath(remotePath) || ".";
      const entries: any[] = await client.readdir(smbPath, { stats: true });
      const items: RemoteFileItem[] = [];

      for (const entry of entries) {
        const name = typeof entry === "string" ? entry : entry.name;
        if (name === "." || name === ".." || name.startsWith(".")) continue;

        const stats = entry.stats || entry;
        const isDir = stats.isDirectory ? stats.isDirectory() : false;

        items.push({
          name,
          path: remotePath ? path.posix.join(remotePath, name) : name,
          isDirectory: isDir,
          size: isDir ? 0 : (stats.size || 0),
          modified: stats.mtime
            ? new Date(stats.mtime).toISOString()
            : new Date().toISOString(),
          extension: fileExtension(name, isDir),
        });
      }

      return sortFileItems(items);
    } finally {
      if (client) {
        try { await client.disconnect(); } catch {}
      }
    }
  }
}

// =====================================================================
// HTTP API Storage Adapter
// =====================================================================

export class HttpApiStorageAdapter implements StorageAdapter {
  private config: ServerConfig;

  constructor(server: StorageServer) {
    this.config = getConfig(server);
  }

  private get endpoint(): string {
    return this.config.apiEndpoint;
  }

  /** Build authorization and custom headers for API requests. */
  private buildHeaders(): Record<string, string> {
    const headers: Record<string, string> = {};
    if (this.config.headers) Object.assign(headers, this.config.headers);
    if (this.config.apiKey) headers["Authorization"] = `Bearer ${this.config.apiKey}`;
    return headers;
  }

  /** Build a full URL for a specific API action path (e.g. "/upload"). */
  private actionUrl(actionPath: string, defaultPath: string): string {
    return this.endpoint + (actionPath || defaultPath);
  }

  async testConnection(): Promise<{ success: boolean; message: string }> {
    try {
      const response = await fetch(this.endpoint, {
        method: "GET",
        headers: this.buildHeaders(),
      });
      if (response.ok) {
        return { success: true, message: "HTTP API connection successful" };
      }
      return { success: false, message: `HTTP API returned status ${response.status}` };
    } catch (err: any) {
      return { success: false, message: `HTTP API connection failed: ${err.message}` };
    }
  }

  async upload(buffer: Buffer, targetPath: string, filename: string): Promise<{ success: boolean; remotePath: string }> {
    const uploadUrl = this.actionUrl(this.config.uploadPath, "/upload");
    const { Blob } = await import("node:buffer");
    const formData = new FormData();
    formData.append("file", new Blob([buffer]), filename);
    formData.append("path", targetPath);

    const response = await fetch(uploadUrl, {
      method: "POST",
      headers: this.buildHeaders(),
      body: formData,
    });

    if (!response.ok) {
      throw new Error(`Upload returned status ${response.status}`);
    }

    return { success: true, remotePath: path.posix.join(targetPath, filename) };
  }

  async deleteFile(filePath: string): Promise<{ success: boolean }> {
    const deleteUrl = this.actionUrl(this.config.deletePath, "/delete");
    const response = await fetch(deleteUrl, {
      method: "DELETE",
      headers: { ...this.buildHeaders(), "Content-Type": "application/json" },
      body: JSON.stringify({ filePath }),
    });

    if (!response.ok) {
      throw new Error(`Delete returned status ${response.status}`);
    }
    return { success: true };
  }

  getPublicUrl(filePath: string): string | null {
    return buildPublicUrl(this.config, filePath);
  }

  async ensureDirectory(_dirPath: string): Promise<void> {
    // HTTP APIs typically handle directory creation during upload
  }

  async listFiles(remotePath: string): Promise<RemoteFileItem[]> {
    const listUrl = this.actionUrl(this.config.listPath, "/list");
    const params = new URLSearchParams();
    if (remotePath) params.set("path", remotePath);

    const response = await fetch(`${listUrl}?${params.toString()}`, {
      method: "GET",
      headers: this.buildHeaders(),
    });
    if (!response.ok) {
      throw new Error(`List returned status ${response.status}`);
    }

    const data = await response.json();
    const rawItems: any[] = Array.isArray(data) ? data : (data.files || []);

    return rawItems.map((item: any) => ({
      name: item.name || "",
      path: item.path || "",
      isDirectory: !!item.isDirectory,
      size: item.size || 0,
      modified: item.modified || new Date().toISOString(),
      extension: item.extension || undefined,
    }));
  }
}

// =====================================================================
// Factory
// =====================================================================

const ADAPTER_MAP: Record<string, new (s: StorageServer) => StorageAdapter> = {
  local: LocalStorageAdapter,
  ftp: FtpStorageAdapter,
  sftp: SftpStorageAdapter,
  smb: SmbStorageAdapter,
  http_api: HttpApiStorageAdapter,
};

export function createAdapter(server: StorageServer): StorageAdapter {
  const AdapterClass = ADAPTER_MAP[server.type];
  if (!AdapterClass) throw new Error(`Unknown server type: ${server.type}`);
  return new AdapterClass(server);
}
