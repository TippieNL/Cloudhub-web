import type { FileItem } from "@shared/schema";

let storedCredentials: { username: string; password: string } | null = null;

export function setCredentials(username: string, password: string) {
  storedCredentials = { username, password };
  sessionStorage.setItem("auth_credentials", btoa(`${username}:${password}`));
}

export function getStoredAuth(): string | null {
  if (storedCredentials) {
    return btoa(`${storedCredentials.username}:${storedCredentials.password}`);
  }
  const stored = sessionStorage.getItem("auth_credentials");
  return stored;
}

export function clearCredentials() {
  storedCredentials = null;
  sessionStorage.removeItem("auth_credentials");
}

export function isAuthenticated(): boolean {
  return !!getStoredAuth();
}

export function getAuthHeaders(): Record<string, string> {
  const auth = getStoredAuth();
  if (!auth) return {};
  return { Authorization: `Basic ${auth}` };
}

export async function authFetch(
  url: string,
  options: RequestInit = {}
): Promise<Response> {
  const headers = new Headers(options.headers);
  const authHeaders = getAuthHeaders();
  Object.entries(authHeaders).forEach(([key, value]) => {
    headers.set(key, value);
  });

  const res = await fetch(url, {
    ...options,
    headers,
  });

  return res;
}

export function formatFileSize(bytes: number): string {
  if (bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

export function formatDate(dateStr: string): string {
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

  if (diffDays === 0) {
    return date.toLocaleTimeString(undefined, {
      hour: "2-digit",
      minute: "2-digit",
    });
  }
  if (diffDays === 1) return "Yesterday";
  if (diffDays < 7) return `${diffDays} days ago`;

  return date.toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

const thumbnailExts = ["jpg", "jpeg", "png", "webp", "gif", "mp4", "webm", "mov", "mkv", "avi", "ogv"];
const imageExts = ["jpg", "jpeg", "png", "gif", "svg", "webp", "bmp", "ico"];
const videoExts2 = ["mp4", "webm", "mov", "ogv"];

export function isImageFile(item: FileItem): boolean {
  if (item.isDirectory) return false;
  const ext = (item.extension || "").toLowerCase().replace(".", "");
  return imageExts.includes(ext);
}

export function isVideoFile(item: FileItem): boolean {
  if (item.isDirectory) return false;
  const ext = (item.extension || "").toLowerCase().replace(".", "");
  return videoExts2.includes(ext);
}

export function isThumbnailable(item: FileItem): boolean {
  if (item.isDirectory) return false;
  const ext = (item.extension || "").toLowerCase().replace(".", "");
  return thumbnailExts.includes(ext);
}
const videoExts = ["mp4", "webm", "avi", "mov", "mkv"];
const audioExts = ["mp3", "wav", "ogg", "flac", "aac"];
const archiveExts = ["zip", "tar", "gz", "rar", "7z", "bz2", "xz"];
const codeExts = [
  "js", "ts", "jsx", "tsx", "py", "rb", "go", "rs", "java", "c", "cpp",
  "h", "hpp", "cs", "php", "swift", "kt", "scala", "sh", "bash", "zsh",
  "html", "css", "scss", "sass", "less", "json", "xml", "yaml", "yml",
  "toml", "ini", "cfg", "conf", "env", "sql", "graphql", "md", "mdx",
];
const docExts = ["pdf", "doc", "docx", "odt", "rtf"];
const spreadsheetExts = ["xls", "xlsx", "csv", "ods"];
const presentationExts = ["ppt", "pptx", "odp"];

export type FileIconName =
  | "Folder"
  | "FileText"
  | "FileImage"
  | "FileVideo"
  | "FileAudio"
  | "FileArchive"
  | "FileCode"
  | "FileSpreadsheet"
  | "Presentation"
  | "File";

export function getFileIconName(item: FileItem): FileIconName {
  if (item.isDirectory) return "Folder";
  const ext = (item.extension || "").toLowerCase().replace(".", "");
  if (imageExts.includes(ext)) return "FileImage";
  if (videoExts.includes(ext)) return "FileVideo";
  if (audioExts.includes(ext)) return "FileAudio";
  if (archiveExts.includes(ext)) return "FileArchive";
  if (codeExts.includes(ext)) return "FileCode";
  if (docExts.includes(ext)) return "FileText";
  if (spreadsheetExts.includes(ext)) return "FileSpreadsheet";
  if (presentationExts.includes(ext)) return "Presentation";
  return "File";
}
