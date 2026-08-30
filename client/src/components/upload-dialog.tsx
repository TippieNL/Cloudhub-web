import { useState, useRef, useCallback } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Upload, X, File as FileIcon } from "lucide-react";
import { formatFileSize, authFetch } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import type { StorageServer } from "@shared/schema";

interface UploadDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  targetPath: string;
  onUploadComplete: () => void;
}

interface UploadFileEntry {
  file: File;
  progress: number;
  status: "pending" | "uploading" | "done" | "error";
  error?: string;
}

export function UploadDialog({
  open,
  onOpenChange,
  targetPath,
  onUploadComplete,
}: UploadDialogProps) {
  const [files, setFiles] = useState<UploadFileEntry[]>([]);
  const [uploading, setUploading] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  const [selectedServerId, setSelectedServerId] = useState<string>("");
  const fileInputRef = useRef<HTMLInputElement>(null);
  const { toast } = useToast();

  const serversQuery = useQuery<StorageServer[]>({
    queryKey: ["/api/servers/active"],
  });
  const activeServers = serversQuery.data || [];

  const addFiles = useCallback((newFiles: FileList | File[]) => {
    const entries: UploadFileEntry[] = Array.from(newFiles).map((file) => ({
      file,
      progress: 0,
      status: "pending" as const,
    }));
    setFiles((prev) => [...prev, ...entries]);
  }, []);

  const removeFile = useCallback((index: number) => {
    setFiles((prev) => prev.filter((_, i) => i !== index));
  }, []);

  const handleDrop = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault();
      setDragOver(false);
      if (e.dataTransfer.files.length > 0) {
        addFiles(e.dataTransfer.files);
      }
    },
    [addFiles]
  );

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setDragOver(true);
  }, []);

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setDragOver(false);
  }, []);

  const handleUpload = async () => {
    if (files.length === 0) return;
    setUploading(true);

    const formData = new FormData();
    formData.append("targetPath", targetPath);
    if (selectedServerId && selectedServerId !== "__local__") {
      formData.append("serverId", selectedServerId);
    }
    files.forEach((entry) => {
      formData.append("files", entry.file);
    });

    setFiles((prev) =>
      prev.map((f) => ({ ...f, status: "uploading" as const, progress: 50 }))
    );

    try {
      const res = await authFetch("/api/files/upload", {
        method: "POST",
        body: formData,
      });

      if (res.ok) {
        setFiles((prev) =>
          prev.map((f) => ({ ...f, status: "done" as const, progress: 100 }))
        );
        toast({ title: "Upload complete", description: `${files.length} file(s) uploaded successfully.` });
        setTimeout(() => {
          setFiles([]);
          setSelectedServerId("");
          onOpenChange(false);
          onUploadComplete();
        }, 500);
      } else {
        const text = await res.text();
        setFiles((prev) =>
          prev.map((f) => ({
            ...f,
            status: "error" as const,
            error: text || "Upload failed",
          }))
        );
        toast({ title: "Upload failed", description: text || "An error occurred during upload.", variant: "destructive" });
      }
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : "Upload failed";
      setFiles((prev) =>
        prev.map((f) => ({
          ...f,
          status: "error" as const,
          error: errorMsg,
        }))
      );
      toast({ title: "Upload failed", description: errorMsg, variant: "destructive" });
    } finally {
      setUploading(false);
    }
  };

  const handleClose = () => {
    if (!uploading) {
      setFiles([]);
      setSelectedServerId("");
      onOpenChange(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Upload Files</DialogTitle>
          <DialogDescription>
            Upload files to {targetPath === "/" ? "root" : targetPath}
          </DialogDescription>
        </DialogHeader>

        <div
          data-testid="drop-zone"
          className={`border-2 border-dashed rounded-md p-8 text-center transition-colors cursor-pointer ${
            dragOver
              ? "border-primary bg-primary/5"
              : "border-muted-foreground/25"
          }`}
          onDrop={handleDrop}
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onClick={() => fileInputRef.current?.click()}
        >
          <Upload className="mx-auto h-8 w-8 text-muted-foreground mb-2" />
          <p className="text-sm text-muted-foreground">
            Drag and drop files here, or click to browse
          </p>
          <input
            ref={fileInputRef}
            type="file"
            multiple
            className="hidden"
            data-testid="input-file-upload"
            onChange={(e) => {
              if (e.target.files) addFiles(e.target.files);
              e.target.value = "";
            }}
          />
        </div>

        {activeServers.length > 0 && (
          <div className="space-y-1.5">
            <label className="text-sm font-medium">Storage Server</label>
            <Select value={selectedServerId} onValueChange={setSelectedServerId}>
              <SelectTrigger data-testid="select-server">
                <SelectValue placeholder="Local (default)" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__local__" data-testid="select-server-local">Local (default)</SelectItem>
                {activeServers.map((s) => (
                  <SelectItem key={s.id} value={String(s.id)} data-testid={`select-server-${s.id}`}>
                    {s.name} ({s.type})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {files.length > 0 && (
          <ScrollArea className="max-h-48">
            <div className="space-y-2">
              {files.map((entry, i) => (
                <div
                  key={`${entry.file.name}-${i}`}
                  className="flex items-center gap-3 px-2 py-1.5 rounded-md bg-muted/50"
                  data-testid={`upload-file-${i}`}
                >
                  <FileIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm truncate">{entry.file.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {formatFileSize(entry.file.size)}
                    </p>
                    {entry.status === "uploading" && (
                      <Progress value={entry.progress} className="h-1 mt-1" />
                    )}
                    {entry.status === "error" && (
                      <p className="text-xs text-destructive mt-0.5">
                        {entry.error}
                      </p>
                    )}
                  </div>
                  {entry.status === "pending" && !uploading && (
                    <Button
                      size="icon"
                      variant="ghost"
                      onClick={() => removeFile(i)}
                      data-testid={`button-remove-upload-${i}`}
                    >
                      <X className="h-3 w-3" />
                    </Button>
                  )}
                  {entry.status === "done" && (
                    <span className="text-xs text-muted-foreground">Done</span>
                  )}
                </div>
              ))}
            </div>
          </ScrollArea>
        )}

        <DialogFooter className="gap-2">
          <Button
            variant="outline"
            onClick={handleClose}
            disabled={uploading}
            data-testid="button-cancel-upload"
          >
            Cancel
          </Button>
          <Button
            onClick={handleUpload}
            disabled={files.length === 0 || uploading}
            data-testid="button-start-upload"
          >
            {uploading ? "Uploading..." : `Upload ${files.length} file(s)`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
