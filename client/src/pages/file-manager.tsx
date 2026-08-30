import { useState, useEffect, useMemo, useCallback, useRef } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { queryClient, apiRequest } from "@/lib/queryClient";
import { authFetch, formatFileSize, formatDate, getFileIconName, isThumbnailable, getStoredAuth, isImageFile, isVideoFile } from "@/lib/api";
import { FILE_ICON_MAP } from "@/lib/constants";
import { getErrorMessage } from "@/lib/errorHandler";
import type { FileItem, ServerConfig } from "@shared/schema";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Skeleton } from "@/components/ui/skeleton";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
  DropdownMenuLabel,
} from "@/components/ui/dropdown-menu";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { UploadDialog } from "@/components/upload-dialog";
import {
  File,
  FileImage,
  ChevronRight,
  Download,
  Trash2,
  Pencil,
  Plus,
  Upload,
  MoreVertical,
  FolderOpen,
  Home,
  RefreshCw,
  Archive,
  AlertCircle,
  Share2,
  Link,
  Copy,
  Check,
  Info,
  FolderDown,
  ExternalLink,
  Play,
  Loader2,
  LayoutGrid,
  LayoutList,
  Film,
  Image as ImageIcon,
} from "lucide-react";

function Thumbnail({ item, className, imgClassName }: { item: FileItem; className?: string; imgClassName?: string }) {
  const [blobUrl, setBlobUrl] = useState<string | null>(null);
  const [error, setError] = useState(false);
  const isVideo = isVideoFile(item);

  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;
    setBlobUrl(null);
    setError(false);
    const controller = new AbortController();
    const auth = getStoredAuth();
    if (!auth) { setError(true); return; }
    const url = `/api/thumbnail?path=${encodeURIComponent(item.path)}`;
    fetch(url, { headers: { Authorization: `Basic ${auth}` }, signal: controller.signal })
      .then((res) => {
        if (!res.ok) throw new Error("Failed");
        return res.blob();
      })
      .then((blob) => {
        if (!cancelled) {
          objectUrl = URL.createObjectURL(blob);
          setBlobUrl(objectUrl);
        }
      })
      .catch(() => { if (!cancelled) setError(true); });
    return () => {
      cancelled = true;
      controller.abort();
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [item.path]);

  const containerCls = `relative shrink-0 rounded overflow-hidden bg-muted flex items-center justify-center ${className || "h-8 w-8"}`;

  if (error) {
    return (
      <div className={containerCls}>
        {isVideo ? (
          <>
            <Film className="h-1/2 w-1/2 text-muted-foreground/40" style={{ minWidth: 14, minHeight: 14 }} />
            <div className="absolute inset-0 flex items-center justify-center">
              <div className="bg-black/20 rounded-full p-1">
                <Play className="h-3 w-3 text-muted-foreground/60 fill-muted-foreground/60" />
              </div>
            </div>
          </>
        ) : (
          <ImageIcon className="h-1/2 w-1/2 text-muted-foreground/40" style={{ minWidth: 14, minHeight: 14 }} />
        )}
      </div>
    );
  }

  return (
    <div className={containerCls}>
      {!blobUrl && <div className="absolute inset-0 animate-pulse bg-muted-foreground/10" />}
      {blobUrl && (
        <>
          <img
            src={blobUrl}
            alt={item.name}
            className={`h-full w-full ${imgClassName || "object-cover"}`}
            data-testid={`thumb-${item.name}`}
          />
          {isVideo && (
            <div className="absolute inset-0 flex items-center justify-center">
              <div className="bg-black/50 rounded-full p-1">
                <Play className="h-3 w-3 text-white fill-white" />
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function FileIcon({ item, large }: { item: FileItem; large?: boolean }) {
  const iconName = getFileIconName(item);
  const colorClass = item.isDirectory
    ? "text-primary"
    : iconName === "FileImage"
    ? "text-green-600 dark:text-green-400"
    : iconName === "FileCode"
    ? "text-orange-600 dark:text-orange-400"
    : iconName === "FileArchive"
    ? "text-amber-600 dark:text-amber-400"
    : iconName === "FileVideo"
    ? "text-purple-600 dark:text-purple-400"
    : iconName === "FileAudio"
    ? "text-pink-600 dark:text-pink-400"
    : "text-muted-foreground";

  if (isThumbnailable(item)) {
    return large
      ? <Thumbnail item={item} className="w-full h-32 rounded-t-md rounded-b-none" imgClassName="object-contain" />
      : <Thumbnail item={item} />;
  }

  const Icon = FILE_ICON_MAP[iconName] || File;
  if (large) {
    return (
      <div className="w-full h-32 flex items-center justify-center bg-muted/40 rounded-t-md rounded-b-none">
        <Icon className={`h-12 w-12 ${colorClass}`} />
      </div>
    );
  }
  return <Icon className={`h-5 w-5 shrink-0 ${colorClass}`} />;
}

function FilePreviewDialog({
  item,
  onClose,
  onDownload,
}: {
  item: FileItem | null;
  onClose: () => void;
  onDownload: (path: string) => void;
}) {
  const [blobUrl, setBlobUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!item) {
      setBlobUrl(null);
      setLoading(false);
      setError(false);
      return;
    }
    let cancelled = false;
    let objectUrl: string | null = null;
    setLoading(true);
    setError(false);
    setBlobUrl(null);
    const auth = getStoredAuth();
    if (!auth) { setError(true); setLoading(false); return; }
    const url = `/api/files/download?path=${encodeURIComponent(item.path)}`;
    fetch(url, { headers: { Authorization: `Basic ${auth}` } })
      .then((res) => {
        if (!res.ok) throw new Error("Failed");
        return res.blob();
      })
      .then((blob) => {
        if (!cancelled) {
          objectUrl = URL.createObjectURL(blob);
          setBlobUrl(objectUrl);
        }
      })
      .catch(() => { if (!cancelled) setError(true); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [item?.path]);

  const isVideo = item ? isVideoFile(item) : false;
  const name = item?.name ?? "";

  return (
    <Dialog open={!!item} onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent
        className="sm:max-w-3xl max-h-[90vh] flex flex-col"
        data-testid="dialog-preview"
      >
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 truncate">
            {isVideo ? <Play className="h-4 w-4 shrink-0" /> : <FileImage className="h-4 w-4 shrink-0" />}
            <span className="truncate">{name}</span>
          </DialogTitle>
        </DialogHeader>

        <div className="flex-1 flex items-center justify-center overflow-hidden min-h-[200px] bg-muted/30 rounded-md">
          {loading && (
            <div className="flex flex-col items-center gap-2 text-muted-foreground" data-testid="preview-loading">
              <Loader2 className="h-8 w-8 animate-spin" />
              <span className="text-sm">Loading preview…</span>
            </div>
          )}
          {error && (
            <div className="flex flex-col items-center gap-2 text-muted-foreground" data-testid="preview-error">
              <AlertCircle className="h-8 w-8" />
              <span className="text-sm">Could not load preview</span>
            </div>
          )}
          {blobUrl && !isVideo && (
            <img
              src={blobUrl}
              alt={name}
              className="max-w-full max-h-[60vh] object-contain rounded"
              data-testid="preview-image"
            />
          )}
          {blobUrl && isVideo && (
            <video
              src={blobUrl}
              controls
              className="max-w-full max-h-[60vh] rounded"
              data-testid="preview-video"
            />
          )}
        </div>

        <DialogFooter className="gap-2 pt-2">
          <Button variant="outline" onClick={onClose} data-testid="button-preview-close">
            Close
          </Button>
          <Button
            onClick={() => { if (item) onDownload(item.path); }}
            data-testid="button-preview-download"
          >
            <Download className="h-4 w-4 mr-2" />
            Download
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function FileManager() {
  const [currentPath, setCurrentPath] = useState("/");
  const [selectedFiles, setSelectedFiles] = useState<Set<string>>(new Set());
  const [uploadOpen, setUploadOpen] = useState(false);
  const [mkdirOpen, setMkdirOpen] = useState(false);
  const [mkdirName, setMkdirName] = useState("");
  const [deleteTarget, setDeleteTarget] = useState<FileItem | null>(null);
  const [renameTarget, setRenameTarget] = useState<FileItem | null>(null);
  const [renameValue, setRenameValue] = useState("");
  const [shareUrl, setShareUrl] = useState<string | null>(null);
  const [shareCopied, setShareCopied] = useState(false);
  const [infoTarget, setInfoTarget] = useState<FileItem | null>(null);
  const [previewTarget, setPreviewTarget] = useState<FileItem | null>(null);
  const [downloadConfirmTarget, setDownloadConfirmTarget] = useState<FileItem | null>(null);
  const previewHistoryActiveRef = useRef(false);
  const [viewMode, setViewMode] = useState<"list" | "grid">(() => {
    try { return (localStorage.getItem("fm-view-mode") as "list" | "grid") || "list"; } catch { return "list"; }
  });
  const { toast } = useToast();

  const toggleViewMode = useCallback(() => {
    setViewMode((prev) => {
      const next = prev === "list" ? "grid" : "list";
      try { localStorage.setItem("fm-view-mode", next); } catch {}
      return next;
    });
  }, []);

  const configQuery = useQuery<ServerConfig>({
    queryKey: ["/api/files/config"],
  });

  const filesQuery = useQuery<FileItem[]>({
    queryKey: ["/api/files/list", `?path=${encodeURIComponent(currentPath)}`],
  });

  const config = configQuery.data;
  const files = filesQuery.data || [];

  const sortedFiles = useMemo(() => {
    return [...files]
      .filter((f) => f.name !== ".thumbnails")
      .sort((a, b) => {
        if (a.isDirectory && !b.isDirectory) return -1;
        if (!a.isDirectory && b.isDirectory) return 1;
        return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
      });
  }, [files]);

  const breadcrumbs = useMemo(() => {
    const parts = currentPath.split("/").filter(Boolean);
    const crumbs = [{ name: "Root", path: "/" }];
    let acc = "";
    for (const part of parts) {
      acc += `/${part}`;
      crumbs.push({ name: part, path: acc });
    }
    return crumbs;
  }, [currentPath]);

  const allSelected =
    sortedFiles.length > 0 && selectedFiles.size === sortedFiles.length;
  const someSelected = selectedFiles.size > 0 && !allSelected;

  const toggleSelectAll = useCallback(() => {
    if (allSelected) {
      setSelectedFiles(new Set());
    } else {
      setSelectedFiles(new Set(sortedFiles.map((f) => f.path)));
    }
  }, [allSelected, sortedFiles]);

  const toggleSelect = useCallback((path: string) => {
    setSelectedFiles((prev) => {
      const next = new Set(prev);
      if (next.has(path)) {
        next.delete(path);
      } else {
        next.add(path);
      }
      return next;
    });
  }, []);

  const navigateTo = useCallback((path: string) => {
    setCurrentPath(path);
    setSelectedFiles(new Set());
  }, []);

  const openPreview = useCallback((item: FileItem) => {
    if (previewHistoryActiveRef.current) {
      setPreviewTarget(item);
      return;
    }

    previewHistoryActiveRef.current = true;
    window.history.pushState(
      { ...(window.history.state || {}), cfhPreviewPath: item.path },
      "",
      window.location.href
    );
    setPreviewTarget(item);
  }, []);

  const closePreview = useCallback(() => {
    if (previewHistoryActiveRef.current) {
      window.history.back();
      return;
    }

    setPreviewTarget(null);
  }, []);

  useEffect(() => {
    const handlePopState = (event: PopStateEvent) => {
      const previewPath = event.state?.cfhPreviewPath;

      if (previewPath) {
        previewHistoryActiveRef.current = true;
        const previewItem = files.find((file) => file.path === previewPath);
        setPreviewTarget(previewItem ?? null);
        return;
      }

      previewHistoryActiveRef.current = false;
      setPreviewTarget(null);
    };

    window.addEventListener("popstate", handlePopState);
    return () => window.removeEventListener("popstate", handlePopState);
  }, [files]);

  const handleItemClick = useCallback(
    (item: FileItem) => {
      if (item.isDirectory) {
        navigateTo(item.path);
      } else if (isImageFile(item) || isVideoFile(item)) {
        openPreview(item);
      } else {
        setDownloadConfirmTarget(item);
      }
    },
    [navigateTo, openPreview]
  );

  const handleDownload = useCallback((filePath: string) => {
    const url = `/api/files/download?path=${encodeURIComponent(filePath)}`;
    const auth = getStoredAuth();
    if (!auth) {
      toast({ title: "Download failed", description: "Not authenticated.", variant: "destructive" });
      return;
    }
    const xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.setRequestHeader("Authorization", `Basic ${auth}`);
    xhr.responseType = "blob";
    xhr.onload = () => {
      if (xhr.status === 200) {
        const blob = xhr.response;
        const downloadUrl = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = downloadUrl;
        a.download = filePath.split("/").pop() || "download";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(downloadUrl);
      } else {
        toast({ title: "Download failed", description: `Server returned ${xhr.status}`, variant: "destructive" });
      }
    };
    xhr.onerror = () => {
      toast({ title: "Download failed", description: "Network error occurred.", variant: "destructive" });
    };
    xhr.send();
  }, [toast]);

  const handleDownloadZip = useCallback(async () => {
    if (selectedFiles.size === 0) return;
    try {
      const res = await authFetch("/api/files/download-zip", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ files: Array.from(selectedFiles) }),
      });
      if (res.ok) {
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = "files.zip";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        toast({ title: "Download started", description: "ZIP file is being downloaded." });
      } else {
        toast({ title: "Download failed", variant: "destructive" });
      }
    } catch {
      toast({ title: "Download failed", variant: "destructive" });
    }
  }, [selectedFiles, toast]);

  const mkdirMutation = useMutation({
    mutationFn: async (name: string) => {
      const path =
        currentPath === "/" ? `/${name}` : `${currentPath}/${name}`;
      await apiRequest("POST", "/api/files/mkdir", { path });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["/api/files/list"],
      });
      setMkdirOpen(false);
      setMkdirName("");
      toast({ title: "Folder created" });
    },
    onError: (err: Error) => {
      toast({
        title: "Failed to create folder",
        description: getErrorMessage(err),
        variant: "destructive",
      });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: async (path: string) => {
      await apiRequest("DELETE", "/api/files/delete", { path });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["/api/files/list"],
      });
      setDeleteTarget(null);
      setSelectedFiles(new Set());
      toast({ title: "Deleted successfully" });
    },
    onError: (err: Error) => {
      toast({
        title: "Delete failed",
        description: getErrorMessage(err),
        variant: "destructive",
      });
    },
  });

  const renameMutation = useMutation({
    mutationFn: async ({
      oldPath,
      newPath,
    }: {
      oldPath: string;
      newPath: string;
    }) => {
      await apiRequest("POST", "/api/files/rename", { oldPath, newPath });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["/api/files/list"],
      });
      setRenameTarget(null);
      setRenameValue("");
      toast({ title: "Renamed successfully" });
    },
    onError: (err: Error) => {
      toast({
        title: "Rename failed",
        description: getErrorMessage(err),
        variant: "destructive",
      });
    },
  });

  const shareMutation = useMutation({
    mutationFn: async (filePath: string) => {
      const res = await apiRequest("POST", "/api/shares/create", { filePath });
      return res.json() as Promise<{ token: string; url: string; expiresAt: string | null }>;
    },
    onSuccess: (data) => {
      setShareUrl(data.url);
      setShareCopied(false);
    },
    onError: (err: Error) => {
      toast({
        title: "Failed to create share link",
        description: getErrorMessage(err),
        variant: "destructive",
      });
    },
  });

  const handleCopyShareUrl = useCallback(async () => {
    if (!shareUrl) return;
    try {
      await navigator.clipboard.writeText(shareUrl);
      setShareCopied(true);
      toast({ title: "Link copied to clipboard" });
      setTimeout(() => setShareCopied(false), 2000);
    } catch {
      toast({ title: "Failed to copy", variant: "destructive" });
    }
  }, [shareUrl, toast]);

  const handleOpenInNewTab = useCallback((filePath: string) => {
    const auth = getStoredAuth();
    if (!auth) return;
    const url = `/api/files/download?path=${encodeURIComponent(filePath)}`;
    const xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.setRequestHeader("Authorization", `Basic ${auth}`);
    xhr.responseType = "blob";
    xhr.onload = () => {
      if (xhr.status === 200) {
        const blobUrl = URL.createObjectURL(xhr.response);
        window.open(blobUrl, "_blank");
      }
    };
    xhr.send();
  }, []);

  const handleRenameSubmit = () => {
    if (!renameTarget || !renameValue.trim()) return;
    const parentPath = renameTarget.path.substring(
      0,
      renameTarget.path.lastIndexOf("/")
    );
    const newPath = parentPath ? `${parentPath}/${renameValue.trim()}` : `/${renameValue.trim()}`;
    renameMutation.mutate({
      oldPath: renameTarget.path,
      newPath,
    });
  };

  const readOnly = config?.readOnly ?? false;
  const allowDelete = config?.allowDelete ?? true;

  return (
    <div className="flex flex-col h-full">
      <div className="sticky top-0 z-50 bg-background border-b">
        <div className="flex items-center gap-2 px-4 py-3 flex-wrap">
          <nav className="flex items-center gap-1 flex-wrap min-w-0 flex-1" data-testid="breadcrumb-nav">
            {breadcrumbs.map((crumb, i) => (
              <span key={crumb.path} className="flex items-center gap-1">
                {i > 0 && (
                  <ChevronRight className="h-4 w-4 text-muted-foreground shrink-0" />
                )}
                <button
                  onClick={() => navigateTo(crumb.path)}
                  className={`text-sm hover-elevate active-elevate-2 rounded-md px-2 py-1 transition-colors ${
                    i === breadcrumbs.length - 1
                      ? "font-semibold"
                      : "text-muted-foreground"
                  }`}
                  data-testid={`breadcrumb-${i}`}
                >
                  {i === 0 ? (
                    <Home className="h-4 w-4" />
                  ) : (
                    crumb.name
                  )}
                </button>
              </span>
            ))}
          </nav>

          <div className="flex items-center gap-2 flex-wrap">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  size="icon"
                  variant="ghost"
                  onClick={() => {
                    queryClient.invalidateQueries({ queryKey: ["/api/files/list"] });
                  }}
                  data-testid="button-refresh"
                >
                  <RefreshCw className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>Refresh</TooltipContent>
            </Tooltip>

            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  size="icon"
                  variant="ghost"
                  onClick={toggleViewMode}
                  data-testid="button-toggle-view"
                >
                  {viewMode === "grid" ? <LayoutList className="h-4 w-4" /> : <LayoutGrid className="h-4 w-4" />}
                </Button>
              </TooltipTrigger>
              <TooltipContent>{viewMode === "grid" ? "List view" : "Grid view"}</TooltipContent>
            </Tooltip>

            {!readOnly && (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setMkdirOpen(true)}
                  data-testid="button-new-folder"
                >
                  <Plus className="h-4 w-4 mr-1" />
                  <span className="hidden sm:inline">New Folder</span>
                </Button>
                <Button
                  size="sm"
                  onClick={() => setUploadOpen(true)}
                  data-testid="button-upload"
                >
                  <Upload className="h-4 w-4 mr-1" />
                  <span className="hidden sm:inline">Upload</span>
                </Button>
              </>
            )}

            {selectedFiles.size > 0 && (
              <Button
                variant="outline"
                size="sm"
                onClick={handleDownloadZip}
                data-testid="button-download-zip"
              >
                <Archive className="h-4 w-4 mr-1" />
                <span className="hidden sm:inline">Download</span>
                <Badge variant="secondary" className="ml-1">
                  {selectedFiles.size}
                </Badge>
              </Button>
            )}
          </div>
        </div>
      </div>

      <div className="flex-1 overflow-auto">
        {filesQuery.isLoading ? (
          viewMode === "grid" ? (
            <div className="p-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
              {Array.from({ length: 12 }).map((_, i) => (
                <div key={i} className="rounded-lg border overflow-hidden">
                  <Skeleton className="h-32 w-full rounded-none" />
                  <div className="p-2 space-y-1">
                    <Skeleton className="h-3 w-3/4" />
                    <Skeleton className="h-3 w-1/2" />
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="p-4 space-y-3">
              {Array.from({ length: 8 }).map((_, i) => (
                <div key={i} className="flex items-center gap-3 px-2 py-2">
                  <Skeleton className="h-4 w-4 rounded-sm" />
                  <Skeleton className="h-5 w-5 rounded" />
                  <Skeleton className="h-4 flex-1 max-w-xs" />
                  <Skeleton className="h-4 w-16 hidden sm:block" />
                  <Skeleton className="h-4 w-24 hidden md:block" />
                </div>
              ))}
            </div>
          )
        ) : filesQuery.isError ? (
          <div className="flex flex-col items-center justify-center h-64 gap-4 px-4">
            <AlertCircle className="h-12 w-12 text-destructive" />
            <p className="text-muted-foreground text-center">
              Failed to load files. Please check your connection and try again.
            </p>
            <Button
              variant="outline"
              onClick={() => filesQuery.refetch()}
              data-testid="button-retry"
            >
              <RefreshCw className="h-4 w-4 mr-2" />
              Retry
            </Button>
          </div>
        ) : sortedFiles.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 gap-3 px-4">
            <FolderOpen className="h-16 w-16 text-muted-foreground/50" />
            <p className="text-muted-foreground">This folder is empty</p>
            {!readOnly && (
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setUploadOpen(true)}
                  data-testid="button-upload-empty"
                >
                  <Upload className="h-4 w-4 mr-1" />
                  Upload Files
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setMkdirOpen(true)}
                  data-testid="button-mkdir-empty"
                >
                  <Plus className="h-4 w-4 mr-1" />
                  New Folder
                </Button>
              </div>
            )}
          </div>
        ) : viewMode === "grid" ? (
          <div className="p-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            {sortedFiles.map((item) => (
              <div
                key={item.path}
                className={`group relative rounded-lg border overflow-hidden hover-elevate transition-colors cursor-pointer ${
                  selectedFiles.has(item.path) ? "ring-2 ring-primary" : ""
                }`}
                data-testid={`grid-item-${item.name}`}
                onClick={() => handleItemClick(item)}
              >
                <div className="absolute top-1.5 left-1.5 z-10">
                  <Checkbox
                    checked={selectedFiles.has(item.path)}
                    onCheckedChange={(checked) => {
                      if (checked !== "indeterminate") toggleSelect(item.path);
                    }}
                    onClick={(e) => e.stopPropagation()}
                    className="opacity-0 group-hover:opacity-100 data-[state=checked]:opacity-100 bg-background/80"
                    data-testid={`grid-checkbox-${item.name}`}
                  />
                </div>
                <div className="absolute top-1.5 right-1.5 z-10">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                      <Button
                        size="icon"
                        variant="ghost"
                        className="h-6 w-6 opacity-0 group-hover:opacity-100 bg-background/80"
                        data-testid={`grid-menu-${item.name}`}
                      >
                        <MoreVertical className="h-3.5 w-3.5" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-48">
                      <DropdownMenuLabel className="truncate text-xs">{item.name}</DropdownMenuLabel>
                      <DropdownMenuSeparator />
                      {!item.isDirectory && (
                        <DropdownMenuItem onClick={() => handleDownload(item.path)}>
                          <Download className="h-4 w-4 mr-2" />Download
                        </DropdownMenuItem>
                      )}
                      {item.isDirectory && (
                        <DropdownMenuItem onClick={() => navigateTo(item.path)}>
                          <FolderOpen className="h-4 w-4 mr-2" />Open
                        </DropdownMenuItem>
                      )}
                      {!item.isDirectory && (
                        <DropdownMenuItem onClick={() => handleOpenInNewTab(item.path)}>
                          <ExternalLink className="h-4 w-4 mr-2" />Open in new tab
                        </DropdownMenuItem>
                      )}
                      <DropdownMenuSeparator />
                      {!item.isDirectory && (
                        <DropdownMenuItem onClick={() => shareMutation.mutate(item.path)}>
                          <Share2 className="h-4 w-4 mr-2" />Share
                        </DropdownMenuItem>
                      )}
                      {!readOnly && (
                        <>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem onClick={() => { setRenameTarget(item); setRenameValue(item.name); }}>
                            <Pencil className="h-4 w-4 mr-2" />Rename
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="text-destructive focus:text-destructive"
                            onClick={() => setDeleteTarget(item)}
                          >
                            <Trash2 className="h-4 w-4 mr-2" />Delete
                          </DropdownMenuItem>
                        </>
                      )}
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>

                <FileIcon item={item} large />

                <div className="p-2">
                  <p className="text-xs font-medium truncate" title={item.name} data-testid={`grid-name-${item.name}`}>
                    {item.name}
                  </p>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {item.isDirectory ? "Folder" : formatFileSize(item.size)}
                  </p>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div>
            <div className="hidden sm:flex items-center gap-3 px-4 py-2 border-b text-xs text-muted-foreground font-medium uppercase tracking-wider">
              <div className="w-6 flex items-center justify-center">
                <Checkbox
                  checked={allSelected ? true : someSelected ? "indeterminate" : false}
                  onCheckedChange={toggleSelectAll}
                  data-testid="checkbox-select-all"
                />
              </div>
              <div className="w-6" />
              <div className="flex-1 min-w-0">Name</div>
              <div className="w-20 text-right">Size</div>
              <div className="w-28 text-right hidden md:block">Modified</div>
              <div className="w-20" />
            </div>

            <div>
              {sortedFiles.map((item) => (
                <div
                  key={item.path}
                  className={`group flex items-center gap-3 px-4 py-2.5 border-b border-transparent hover-elevate transition-colors ${
                    selectedFiles.has(item.path)
                      ? "bg-accent/50"
                      : ""
                  }`}
                  data-testid={`file-row-${item.name}`}
                >
                  <div className="w-6 flex items-center justify-center">
                    <Checkbox
                      checked={selectedFiles.has(item.path)}
                      onCheckedChange={() => toggleSelect(item.path)}
                      data-testid={`checkbox-${item.name}`}
                    />
                  </div>

                  <button
                    className="flex items-center gap-3 flex-1 min-w-0 text-left"
                    onClick={() => handleItemClick(item)}
                    data-testid={`link-${item.name}`}
                  >
                    <FileIcon item={item} />
                    <span className="truncate text-sm font-medium">
                      {item.name}
                    </span>
                    {item.isDirectory && (
                      <ChevronRight className="h-4 w-4 text-muted-foreground shrink-0 opacity-0 group-hover:opacity-100 sm:opacity-0 sm:group-hover:opacity-100" style={{ visibility: "visible" }} />
                    )}
                  </button>

                  <div className="w-20 text-right text-xs text-muted-foreground hidden sm:block">
                    {item.isDirectory ? "--" : formatFileSize(item.size)}
                  </div>

                  <div className="w-28 text-right text-xs text-muted-foreground hidden md:block">
                    {formatDate(item.modified)}
                  </div>

                  <div className="w-8 flex items-center justify-end">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button
                          size="icon"
                          variant="ghost"
                          className="h-8 w-8 sm:opacity-0 sm:group-hover:opacity-100 sm:focus:opacity-100"
                          data-testid={`menu-${item.name}`}
                        >
                          <MoreVertical className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-56">
                        <DropdownMenuLabel className="truncate font-semibold text-sm">
                          {item.name}
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />

                        {!item.isDirectory && (
                          <DropdownMenuItem
                            onClick={() => handleDownload(item.path)}
                            data-testid={`action-download-${item.name}`}
                          >
                            <Download className="h-4 w-4 mr-2" />
                            Download
                          </DropdownMenuItem>
                        )}
                        {item.isDirectory && (
                          <DropdownMenuItem
                            onClick={() => navigateTo(item.path)}
                            data-testid={`action-open-${item.name}`}
                          >
                            <FolderOpen className="h-4 w-4 mr-2" />
                            Open
                          </DropdownMenuItem>
                        )}
                        {!item.isDirectory && (
                          <DropdownMenuItem
                            onClick={() => handleOpenInNewTab(item.path)}
                            data-testid={`action-open-tab-${item.name}`}
                          >
                            <ExternalLink className="h-4 w-4 mr-2" />
                            Open in new tab
                          </DropdownMenuItem>
                        )}

                        <DropdownMenuSeparator />

                        {!item.isDirectory && (
                          <DropdownMenuItem
                            onClick={() => shareMutation.mutate(item.path)}
                            data-testid={`action-share-${item.name}`}
                          >
                            <Share2 className="h-4 w-4 mr-2" />
                            Share
                          </DropdownMenuItem>
                        )}

                        {(!item.isDirectory) && <DropdownMenuSeparator />}

                        {!readOnly && (
                          <DropdownMenuItem
                            onClick={() => {
                              setRenameTarget(item);
                              setRenameValue(item.name);
                            }}
                            data-testid={`action-rename-${item.name}`}
                          >
                            <Pencil className="h-4 w-4 mr-2" />
                            Rename
                          </DropdownMenuItem>
                        )}
                        {!readOnly && allowDelete && (
                          <DropdownMenuItem
                            onClick={() => setDeleteTarget(item)}
                            className="text-destructive focus:text-destructive"
                            data-testid={`action-delete-${item.name}`}
                          >
                            <Trash2 className="h-4 w-4 mr-2" />
                            Delete
                          </DropdownMenuItem>
                        )}

                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          onClick={() => setInfoTarget(item)}
                          data-testid={`action-info-${item.name}`}
                        >
                          <Info className="h-4 w-4 mr-2" />
                          {item.isDirectory ? "Folder info" : "File info"}
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {sortedFiles.length > 0 && (
        <div className="border-t px-4 py-2 text-xs text-muted-foreground flex items-center gap-4 flex-wrap">
          <span data-testid="text-file-count">
            {sortedFiles.filter((f) => !f.isDirectory).length} file(s),{" "}
            {sortedFiles.filter((f) => f.isDirectory).length} folder(s)
          </span>
          {selectedFiles.size > 0 && (
            <span data-testid="text-selected-count">
              {selectedFiles.size} selected
            </span>
          )}
        </div>
      )}

      <UploadDialog
        open={uploadOpen}
        onOpenChange={setUploadOpen}
        targetPath={currentPath}
        onUploadComplete={() => {
          queryClient.invalidateQueries({ queryKey: ["/api/files/list"] });
        }}
      />

      <Dialog open={mkdirOpen} onOpenChange={setMkdirOpen}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>New Folder</DialogTitle>
            <DialogDescription>
              Create a new folder in the current directory.
            </DialogDescription>
          </DialogHeader>
          <Input
            placeholder="Folder name"
            value={mkdirName}
            onChange={(e) => setMkdirName(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && mkdirName.trim()) {
                mkdirMutation.mutate(mkdirName.trim());
              }
            }}
            data-testid="input-folder-name"
          />
          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setMkdirOpen(false);
                setMkdirName("");
              }}
              data-testid="button-cancel-mkdir"
            >
              Cancel
            </Button>
            <Button
              onClick={() => mkdirMutation.mutate(mkdirName.trim())}
              disabled={!mkdirName.trim() || mkdirMutation.isPending}
              data-testid="button-create-folder"
            >
              {mkdirMutation.isPending ? "Creating..." : "Create"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={!!deleteTarget}
        onOpenChange={(open) => {
          if (!open) setDeleteTarget(null);
        }}
      >
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Delete {deleteTarget?.isDirectory ? "Folder" : "File"}</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{deleteTarget?.name}"? This action
              cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => setDeleteTarget(null)}
              data-testid="button-cancel-delete"
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                if (deleteTarget) {
                  deleteMutation.mutate(deleteTarget.path);
                }
              }}
              disabled={deleteMutation.isPending}
              data-testid="button-confirm-delete"
            >
              {deleteMutation.isPending ? "Deleting..." : "Delete"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={!!renameTarget}
        onOpenChange={(open) => {
          if (!open) {
            setRenameTarget(null);
            setRenameValue("");
          }
        }}
      >
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Rename</DialogTitle>
            <DialogDescription>
              Enter a new name for "{renameTarget?.name}".
            </DialogDescription>
          </DialogHeader>
          <Input
            placeholder="New name"
            value={renameValue}
            onChange={(e) => setRenameValue(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") handleRenameSubmit();
            }}
            data-testid="input-rename"
          />
          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setRenameTarget(null);
                setRenameValue("");
              }}
              data-testid="button-cancel-rename"
            >
              Cancel
            </Button>
            <Button
              onClick={handleRenameSubmit}
              disabled={!renameValue.trim() || renameMutation.isPending}
              data-testid="button-confirm-rename"
            >
              {renameMutation.isPending ? "Renaming..." : "Rename"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={!!shareUrl}
        onOpenChange={(open) => {
          if (!open) {
            setShareUrl(null);
            setShareCopied(false);
          }
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Link className="h-4 w-4" />
              Share Link
            </DialogTitle>
            <DialogDescription>
              Anyone with this link can access the file without logging in.
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-center gap-2">
            <Input
              readOnly
              value={shareUrl || ""}
              className="flex-1 text-sm"
              data-testid="input-share-url"
            />
            <Button
              size="icon"
              variant="outline"
              onClick={handleCopyShareUrl}
              data-testid="button-copy-share"
            >
              {shareCopied ? (
                <Check className="h-4 w-4 text-green-600" />
              ) : (
                <Copy className="h-4 w-4" />
              )}
            </Button>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setShareUrl(null);
                setShareCopied(false);
              }}
              data-testid="button-close-share"
            >
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={!!infoTarget}
        onOpenChange={(open) => {
          if (!open) setInfoTarget(null);
        }}
      >
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Info className="h-4 w-4" />
              {infoTarget?.isDirectory ? "Folder" : "File"} Info
            </DialogTitle>
            <DialogDescription>
              Details about this {infoTarget?.isDirectory ? "folder" : "file"}.
            </DialogDescription>
          </DialogHeader>
          {infoTarget && (
            <div className="space-y-3 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Name</span>
                <span className="font-medium text-right truncate max-w-[200px]" data-testid="info-name">
                  {infoTarget.name}
                </span>
              </div>
              <Separator />
              <div className="flex justify-between">
                <span className="text-muted-foreground">Type</span>
                <span className="font-medium" data-testid="info-type">
                  {infoTarget.isDirectory ? "Folder" : infoTarget.name.split(".").pop()?.toUpperCase() || "File"}
                </span>
              </div>
              <Separator />
              {!infoTarget.isDirectory && (
                <>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Size</span>
                    <span className="font-medium" data-testid="info-size">
                      {formatFileSize(infoTarget.size)}
                    </span>
                  </div>
                  <Separator />
                </>
              )}
              <div className="flex justify-between">
                <span className="text-muted-foreground">Modified</span>
                <span className="font-medium" data-testid="info-modified">
                  {formatDate(infoTarget.modified)}
                </span>
              </div>
              <Separator />
              <div className="flex justify-between">
                <span className="text-muted-foreground">Path</span>
                <span className="font-medium text-right truncate max-w-[200px]" data-testid="info-path">
                  {infoTarget.path}
                </span>
              </div>
            </div>
          )}
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setInfoTarget(null)}
              data-testid="button-close-info"
            >
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <FilePreviewDialog
        item={previewTarget}
        onClose={closePreview}
        onDownload={(path) => {
          handleDownload(path);
          closePreview();
        }}
      />

      <Dialog
        open={!!downloadConfirmTarget}
        onOpenChange={(open) => { if (!open) setDownloadConfirmTarget(null); }}
      >
        <DialogContent className="sm:max-w-sm" data-testid="dialog-download-confirm">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Download className="h-4 w-4" />
              Download File
            </DialogTitle>
            <DialogDescription>
              Do you want to download "{downloadConfirmTarget?.name}"?
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => setDownloadConfirmTarget(null)}
              data-testid="button-cancel-download-confirm"
            >
              Cancel
            </Button>
            <Button
              onClick={() => {
                if (downloadConfirmTarget) {
                  handleDownload(downloadConfirmTarget.path);
                  setDownloadConfirmTarget(null);
                }
              }}
              data-testid="button-confirm-download"
            >
              <Download className="h-4 w-4 mr-2" />
              Download
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
