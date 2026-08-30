import {
  Folder,
  FileText,
  FileImage,
  FileVideo,
  FileAudio,
  FileArchive,
  FileCode,
  File,
  FileSpreadsheet,
  Presentation,
} from "lucide-react";

/** Map from icon name (returned by getFileIconName) to Lucide component. */
export const FILE_ICON_MAP: Record<string, typeof File> = {
  Folder,
  FileText,
  FileImage,
  FileVideo,
  FileAudio,
  FileArchive,
  FileCode,
  File,
  FileSpreadsheet,
  Presentation,
};

/** Human-readable labels for storage server types. */
export const SERVER_TYPE_LABELS: Record<string, string> = {
  local: "Local",
  ftp: "FTP",
  sftp: "SFTP",
  smb: "SMB",
  http_api: "HTTP API",
};

/** Badge color classes per server type. */
export const SERVER_TYPE_BADGE_CLASSES: Record<string, string> = {
  local: "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200",
  ftp: "bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200",
  sftp: "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200",
  smb: "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200",
  http_api: "bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200",
};
