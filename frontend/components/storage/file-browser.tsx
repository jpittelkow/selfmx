"use client";

import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  File,
  FolderOpen,
  FileText,
  Image,
  MoreHorizontal,
  Download,
  Trash2,
  Pencil,
  MoveRight,
  Loader2,
  Folder,
} from "lucide-react";
import type { FileManagerItem } from "@/lib/api";
import { formatBytes, formatTimestamp } from "@/lib/utils";

function formatSize(bytes: number | null): string {
  if (bytes === null || bytes === undefined) return "—";
  return formatBytes(bytes);
}

function getFileIcon(item: FileManagerItem) {
  if (item.isDirectory) return <FolderOpen className="h-5 w-5 text-amber-600 dark:text-amber-400" />;
  const ext = item.name.split(".").pop()?.toLowerCase();
  const imageExts = ["jpg", "jpeg", "png", "gif", "webp", "svg"];
  if (ext && imageExts.includes(ext)) return <Image className="h-5 w-5 text-blue-600 dark:text-blue-400" />;
  const textExts = ["txt", "md", "json", "log", "csv"];
  if (ext && textExts.includes(ext)) return <FileText className="h-5 w-5 text-muted-foreground" />;
  return <File className="h-5 w-5 text-muted-foreground" />;
}

export interface FileBrowserProps {
  items: FileManagerItem[];
  currentPath: string;
  loading?: boolean;
  selectedPath?: string | null;
  onNavigate: (path: string) => void;
  onRefresh: () => void;
  onSelect?: (item: FileManagerItem) => void;
  onDownload: (path: string) => void;
  onDelete: (path: string) => void;
  onRename: (path: string, currentName: string) => void;
  onMove: (path: string) => void;
  onPreview?: (item: FileManagerItem) => void;
}

export function FileBrowser({
  items,
  currentPath,
  loading = false,
  selectedPath,
  onNavigate,
  onRefresh,
  onSelect,
  onDownload,
  onDelete,
  onRename,
  onMove,
  onPreview,
}: FileBrowserProps) {
  if (loading) {
    return (
      <div className="flex items-center justify-center rounded-md border py-16">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center rounded-md border py-16 text-center text-muted-foreground">
        <Folder className="h-12 w-12 mb-4 opacity-50" />
        <p className="font-heading font-medium">This folder is empty</p>
        <p className="text-sm mt-1">
          {currentPath ? "Navigate up or upload files here." : "Upload files to get started."}
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-x-auto rounded-md border min-w-[600px]">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="w-[40px]"></TableHead>
            <TableHead>Name</TableHead>
            <TableHead className="hidden md:table-cell">Size</TableHead>
            <TableHead className="hidden lg:table-cell">Type</TableHead>
            <TableHead className="hidden md:table-cell">Modified</TableHead>
            <TableHead className="w-[60px] text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {items.map((item) => (
            <TableRow
              key={item.path}
              className={
                selectedPath === item.path
                  ? "bg-muted/50"
                  : item.isDirectory
                    ? "cursor-pointer hover:bg-muted/50"
                    : ""
              }
              onClick={() =>
                item.isDirectory
                  ? onNavigate(item.path)
                  : onSelect?.(item)
              }
            >
              <TableCell className="w-[40px]">{getFileIcon(item)}</TableCell>
              <TableCell>
                <span className="font-medium">{item.name}</span>
              </TableCell>
              <TableCell className="hidden md:table-cell text-muted-foreground">
                {item.isDirectory ? "—" : formatSize(item.size)}
              </TableCell>
              <TableCell className="hidden lg:table-cell text-muted-foreground">
                {item.isDirectory ? "Folder" : (item.mimeType ?? "—")}
              </TableCell>
              <TableCell className="hidden md:table-cell text-muted-foreground">
                {formatTimestamp(item.lastModified)}
              </TableCell>
              <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="h-8 w-8">
                      <MoreHorizontal className="h-4 w-4" />
                      <span className="sr-only">Actions</span>
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    {item.isDirectory ? (
                      <>
                        <DropdownMenuItem onClick={() => onNavigate(item.path)}>
                          <FolderOpen className="h-4 w-4 mr-2" />
                          Open
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => onRename(item.path, item.name)}>
                          <Pencil className="h-4 w-4 mr-2" />
                          Rename
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => onMove(item.path)}>
                          <MoveRight className="h-4 w-4 mr-2" />
                          Move
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          className="text-destructive focus:text-destructive"
                          onClick={() => onDelete(item.path)}
                        >
                          <Trash2 className="h-4 w-4 mr-2" />
                          Delete
                        </DropdownMenuItem>
                      </>
                    ) : (
                      <>
                        {onPreview && (
                          <DropdownMenuItem onClick={() => onPreview(item)}>
                            <File className="h-4 w-4 mr-2" />
                            Preview
                          </DropdownMenuItem>
                        )}
                        <DropdownMenuItem onClick={() => onDownload(item.path)}>
                          <Download className="h-4 w-4 mr-2" />
                          Download
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => onRename(item.path, item.name)}>
                          <Pencil className="h-4 w-4 mr-2" />
                          Rename
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => onMove(item.path)}>
                          <MoveRight className="h-4 w-4 mr-2" />
                          Move
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          className="text-destructive focus:text-destructive"
                          onClick={() => onDelete(item.path)}
                        >
                          <Trash2 className="h-4 w-4 mr-2" />
                          Delete
                        </DropdownMenuItem>
                      </>
                    )}
                  </DropdownMenuContent>
                </DropdownMenu>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
