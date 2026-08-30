import type { ReactNode } from "react";
import type { FileItem } from "@shared/schema";
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuLabel,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from "@/components/ui/context-menu";
import { Download, ExternalLink, FolderOpen, Info, Pencil, Share2, Trash2 } from "lucide-react";

export function FileItemContextMenu({
  item,
  readOnly,
  allowDelete,
  onOpen,
  onDownload,
  onOpenInNewTab,
  onShare,
  onRename,
  onDelete,
  onInfo,
  children,
}: {
  item: FileItem;
  readOnly: boolean;
  allowDelete: boolean;
  onOpen: () => void;
  onDownload: () => void;
  onOpenInNewTab: () => void;
  onShare: () => void;
  onRename: () => void;
  onDelete: () => void;
  onInfo: () => void;
  children: ReactNode;
}) {
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuLabel className="max-w-[220px] truncate">{item.name}</ContextMenuLabel>
        <ContextMenuSeparator />
        <ContextMenuItem onClick={onOpen}>
          {item.isDirectory ? <FolderOpen className="mr-2 h-4 w-4" /> : <ExternalLink className="mr-2 h-4 w-4" />}
          {item.isDirectory ? "Open" : "Preview"}
        </ContextMenuItem>
        {!item.isDirectory && (
          <ContextMenuItem onClick={onDownload}>
            <Download className="mr-2 h-4 w-4" />
            Download
          </ContextMenuItem>
        )}
        {!item.isDirectory && (
          <ContextMenuItem onClick={onOpenInNewTab}>
            <ExternalLink className="mr-2 h-4 w-4" />
            Open in new tab
          </ContextMenuItem>
        )}
        {!item.isDirectory && (
          <ContextMenuItem onClick={onShare}>
            <Share2 className="mr-2 h-4 w-4" />
            Share
          </ContextMenuItem>
        )}
        {!readOnly && (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem onClick={onRename}>
              <Pencil className="mr-2 h-4 w-4" />
              Rename
            </ContextMenuItem>
            {allowDelete && (
              <ContextMenuItem onClick={onDelete} className="text-destructive focus:text-destructive">
                <Trash2 className="mr-2 h-4 w-4" />
                Delete
              </ContextMenuItem>
            )}
          </>
        )}
        <ContextMenuSeparator />
        <ContextMenuItem onClick={onInfo}>
          <Info className="mr-2 h-4 w-4" />
          {item.isDirectory ? "Folder info" : "File info"}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  );
}
