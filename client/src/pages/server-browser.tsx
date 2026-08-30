import { useState, useCallback } from "react";
import { useQuery } from "@tanstack/react-query";
import { authFetch, formatFileSize, formatDate, getFileIconName } from "@/lib/api";
import { FILE_ICON_MAP, SERVER_TYPE_LABELS, SERVER_TYPE_BADGE_CLASSES } from "@/lib/constants";
import type { FileItem } from "@shared/schema";
import type { StorageServer } from "@shared/schema";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Folder,
  File,
  ChevronRight,
  Home,
  RefreshCw,
  AlertCircle,
  Server,
  FolderOpen,
  Loader2,
} from "lucide-react";

interface BrowseResponse {
  files: FileItem[];
  path: string;
  serverName: string;
  serverType: string;
}

// ----- Path navigation helpers -----

function getPathSegments(currentPath: string): string[] {
  return currentPath ? currentPath.split("/").filter(Boolean) : [];
}

function getParentPath(currentPath: string): string {
  const segments = getPathSegments(currentPath);
  segments.pop();
  return segments.join("/");
}

function getPathAtDepth(currentPath: string, depth: number): string {
  if (depth < 0) return "";
  return getPathSegments(currentPath).slice(0, depth + 1).join("/");
}

// ----- Component -----

export default function ServerBrowser() {
  const [selectedServerId, setSelectedServerId] = useState<string>("");
  const [currentPath, setCurrentPath] = useState<string>("");

  const serversQuery = useQuery<StorageServer[]>({
    queryKey: ["/api/servers/active"],
  });
  const servers = serversQuery.data || [];

  const browseQuery = useQuery<BrowseResponse>({
    queryKey: ["/api/servers", selectedServerId, "browse", currentPath],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (currentPath) params.set("path", currentPath);
      const res = await authFetch(`/api/servers/${selectedServerId}/browse?${params.toString()}`);
      if (!res.ok) {
        const data = await res.json().catch(() => ({ message: "Failed to browse server" }));
        throw new Error(data.message || `Error ${res.status}`);
      }
      return res.json();
    },
    enabled: !!selectedServerId,
    retry: false,
  });

  const files = browseQuery.data?.files || [];
  const pathSegments = getPathSegments(currentPath);
  const selectedServer = servers.find(s => String(s.id) === selectedServerId);

  const handleServerChange = useCallback((value: string) => {
    setSelectedServerId(value);
    setCurrentPath("");
  }, []);

  const navigateUp = useCallback(() => {
    setCurrentPath(prev => getParentPath(prev));
  }, []);

  // ----- Loading skeleton -----

  if (serversQuery.isLoading) {
    return (
      <div className="p-6 space-y-4">
        <Skeleton className="h-7 w-48 mb-2" />
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  // ----- Content state rendering -----

  function renderContent() {
    if (!selectedServerId) {
      return (
        <div className="flex flex-col items-center justify-center h-64 gap-3 px-4">
          <FolderOpen className="h-16 w-16 text-muted-foreground/50" />
          <p className="text-muted-foreground" data-testid="text-empty-select">
            Select a storage server to browse its files
          </p>
          {servers.length === 0 && (
            <p className="text-sm text-muted-foreground/70 text-center max-w-md">
              No active servers found. Add and activate a server on the Servers page first.
            </p>
          )}
        </div>
      );
    }

    if (browseQuery.isLoading) {
      return (
        <div className="flex flex-col items-center justify-center h-64 gap-3">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          <p className="text-sm text-muted-foreground" data-testid="text-loading">Loading files...</p>
        </div>
      );
    }

    if (browseQuery.isError) {
      return (
        <div className="flex flex-col items-center justify-center h-64 gap-4 px-4">
          <AlertCircle className="h-12 w-12 text-destructive" />
          <p className="text-muted-foreground text-center" data-testid="text-browse-error">
            {browseQuery.error instanceof Error ? browseQuery.error.message : "Failed to browse server"}
          </p>
          <Button variant="outline" onClick={() => browseQuery.refetch()} data-testid="button-retry">
            <RefreshCw className="h-4 w-4 mr-2" />
            Retry
          </Button>
        </div>
      );
    }

    if (files.length === 0) {
      return (
        <div className="flex flex-col items-center justify-center h-64 gap-3 px-4">
          <FolderOpen className="h-16 w-16 text-muted-foreground/50" />
          <p className="text-muted-foreground" data-testid="text-empty-folder">This folder is empty</p>
          {currentPath && (
            <Button variant="outline" size="sm" onClick={navigateUp} data-testid="button-go-up">
              Go up
            </Button>
          )}
        </div>
      );
    }

    return (
      <Table data-testid="table-files">
        <TableHeader>
          <TableRow>
            <TableHead className="w-[50%]">Name</TableHead>
            <TableHead className="w-[20%]">Size</TableHead>
            <TableHead className="w-[30%]">Modified</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {currentPath && (
            <TableRow className="cursor-pointer hover:bg-muted/50" onClick={navigateUp} data-testid="row-parent-dir">
              <TableCell className="flex items-center gap-2 font-medium">
                <Folder className="h-4 w-4 text-muted-foreground shrink-0" />
                <span>..</span>
              </TableCell>
              <TableCell className="text-muted-foreground">--</TableCell>
              <TableCell className="text-muted-foreground">--</TableCell>
            </TableRow>
          )}
          {files.map((file) => {
            const IconComponent = FILE_ICON_MAP[getFileIconName(file)] || File;
            const isDir = file.isDirectory;
            return (
              <TableRow
                key={file.path}
                className={isDir ? "cursor-pointer hover:bg-muted/50" : "hover:bg-muted/50"}
                onClick={isDir ? () => setCurrentPath(file.path) : undefined}
                data-testid={`row-file-${file.name}`}
              >
                <TableCell className="flex items-center gap-2 font-medium">
                  <IconComponent
                    className={`h-4 w-4 shrink-0 ${isDir ? "text-blue-500 dark:text-blue-400" : "text-muted-foreground"}`}
                  />
                  <span className={`truncate ${isDir ? "text-blue-600 dark:text-blue-400" : ""}`}>
                    {file.name}
                  </span>
                </TableCell>
                <TableCell className="text-muted-foreground text-sm">
                  {isDir ? "--" : formatFileSize(file.size)}
                </TableCell>
                <TableCell className="text-muted-foreground text-sm">
                  {formatDate(file.modified)}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    );
  }

  return (
    <div className="flex flex-col h-full">
      <div className="p-4 pb-0 space-y-4">
        <div className="flex items-center justify-between gap-4 flex-wrap">
          <div>
            <h1 className="text-xl font-semibold" data-testid="text-page-title">Browse Server Files</h1>
            <p className="text-sm text-muted-foreground" data-testid="text-page-subtitle">
              View files on your configured storage servers
            </p>
          </div>
        </div>

        <div className="flex items-center gap-3 flex-wrap">
          <div className="w-72">
            <Select value={selectedServerId} onValueChange={handleServerChange}>
              <SelectTrigger data-testid="select-server">
                <SelectValue placeholder="Select a server to browse" />
              </SelectTrigger>
              <SelectContent>
                {servers.map((server) => (
                  <SelectItem key={server.id} value={String(server.id)} data-testid={`option-server-${server.id}`}>
                    <div className="flex items-center gap-2">
                      <Server className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <span>{server.name}</span>
                      <span className="text-xs text-muted-foreground">
                        ({SERVER_TYPE_LABELS[server.type] || server.type})
                      </span>
                    </div>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {selectedServer && (
            <Badge
              variant="secondary"
              className={`no-default-hover-elevate no-default-active-elevate text-xs ${SERVER_TYPE_BADGE_CLASSES[selectedServer.type] || ""}`}
              data-testid="badge-server-type"
            >
              {SERVER_TYPE_LABELS[selectedServer.type] || selectedServer.type}
            </Badge>
          )}

          {selectedServerId && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => browseQuery.refetch()}
              disabled={browseQuery.isFetching}
              data-testid="button-refresh"
            >
              <RefreshCw className={`h-4 w-4 mr-1 ${browseQuery.isFetching ? "animate-spin" : ""}`} />
              Refresh
            </Button>
          )}
        </div>

        {selectedServerId && (
          <nav className="flex items-center gap-1 text-sm overflow-x-auto pb-1" data-testid="breadcrumb-nav">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 px-2 shrink-0"
              onClick={() => setCurrentPath("")}
              data-testid="breadcrumb-root"
            >
              <Home className="h-3.5 w-3.5" />
            </Button>
            {pathSegments.map((segment, idx) => (
              <span key={idx} className="flex items-center gap-1 shrink-0">
                <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 px-2"
                  onClick={() => setCurrentPath(getPathAtDepth(currentPath, idx))}
                  data-testid={`breadcrumb-segment-${idx}`}
                >
                  {segment}
                </Button>
              </span>
            ))}
          </nav>
        )}

        <Separator />
      </div>

      <div className="flex-1 overflow-auto p-4 pt-0">
        {renderContent()}
      </div>
    </div>
  );
}
