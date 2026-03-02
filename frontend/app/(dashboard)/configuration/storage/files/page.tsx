"use client";

import { useState, useEffect, useCallback } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useAuth, isAdminUser } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FileBrowser } from "@/components/storage/file-browser";
import { UploadDialog } from "@/components/storage/upload-dialog";
import { FilePreview } from "@/components/storage/file-preview";
import { fileManagerApi, type FileManagerItem } from "@/lib/api";
import { api } from "@/lib/api";
import { toast } from "sonner";
import { Upload, RefreshCw, ChevronRight, Loader2 } from "lucide-react";

const ITEMS_PER_PAGE = 50;

export default function FileManagerPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const pathFromUrl = searchParams.get("path") ?? "";
  const initialPath = pathFromUrl.includes("/") ? pathFromUrl.split("/").slice(0, -1).join("/") : pathFromUrl;
  const { user, isLoading: authLoading } = useAuth();
  const [currentPath, setCurrentPath] = useState(initialPath);
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<FileManagerItem[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [selectedPath, setSelectedPath] = useState<string | null>(null);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [storageSettings, setStorageSettings] = useState<{
    max_upload_size?: number;
    allowed_file_types?: string[];
  }>({ max_upload_size: 10485760 });
  const [renameOpen, setRenameOpen] = useState(false);
  const [renamePath, setRenamePath] = useState("");
  const [renameName, setRenameName] = useState("");
  const [renameSubmitting, setRenameSubmitting] = useState(false);
  const [moveOpen, setMoveOpen] = useState(false);
  const [movePath, setMovePath] = useState("");
  const [moveDestination, setMoveDestination] = useState("");
  const [moveSubmitting, setMoveSubmitting] = useState(false);
  const [previewItem, setPreviewItem] = useState<FileManagerItem | null>(null);

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fileManagerApi.listFiles(currentPath, page, ITEMS_PER_PAGE);
      setItems(res.data.items);
      setTotal(res.data.total);
    } catch {
      toast.error("Failed to load files.");
      setItems([]);
      setTotal(0);
    } finally {
      setLoading(false);
    }
  }, [currentPath, page]);

  useEffect(() => {
    if (!user) return;
    if (!isAdminUser(user)) {
      router.push("/mail");
      return;
    }
    loadList();
  }, [user, router, loadList]);


  useEffect(() => {
    api.get<{ settings: Record<string, unknown> }>("/storage-settings").then((res) => {
      const s = res.data.settings || {};
      setStorageSettings({
        max_upload_size: (s.max_upload_size as number) ?? 10485760,
        allowed_file_types: (s.allowed_file_types as string[]) ?? [],
      });
    }).catch(() => {});
  }, []);

  const handleNavigate = (path: string) => {
    setCurrentPath(path);
    setPage(1);
    setSelectedPath(null);
  };

  const handleRefresh = () => loadList();

  const handleDownload = async (path: string) => {
    try {
      const res = await fileManagerApi.downloadFile(path);
      const blob = res.data as unknown as Blob;
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = path.split("/").pop() || "download";
      a.click();
      URL.revokeObjectURL(url);
      toast.success("Download started.");
    } catch {
      toast.error("Download failed.");
    }
  };

  const handleDelete = async (path: string) => {
    if (!confirm(`Delete "${path}"? This cannot be undone.`)) return;
    try {
      await fileManagerApi.deleteFile(path);
      toast.success("Deleted.");
      loadList();
    } catch {
      toast.error("Delete failed.");
    }
  };

  const openRename = (path: string, currentName: string) => {
    setRenamePath(path);
    setRenameName(currentName);
    setRenameOpen(true);
  };

  const submitRename = async () => {
    if (!renameName.trim()) return;
    setRenameSubmitting(true);
    try {
      await fileManagerApi.renameFile(renamePath, renameName.trim());
      toast.success("Renamed.");
      setRenameOpen(false);
      loadList();
    } catch {
      toast.error("Rename failed.");
    } finally {
      setRenameSubmitting(false);
    }
  };

  const openMove = (path: string) => {
    setMovePath(path);
    const parent = path.includes("/") ? path.replace(/\/[^/]+$/, "") : "";
    setMoveDestination(parent);
    setMoveOpen(true);
  };

  const submitMove = async () => {
    setMoveSubmitting(true);
    try {
      await fileManagerApi.moveFile(movePath, moveDestination.trim());
      toast.success("Moved.");
      setMoveOpen(false);
      loadList();
    } catch {
      toast.error("Move failed.");
    } finally {
      setMoveSubmitting(false);
    }
  };

  const breadcrumbSegments = currentPath ? currentPath.split("/").filter(Boolean) : [];

  if (authLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <>
      <div className="space-y-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">File Manager</h1>
          <p className="text-muted-foreground text-sm mt-1">
            Browse and manage files in application storage. Admin only.
          </p>
        </div>

        <Card>
          <CardHeader className="pb-2">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div>
                <CardTitle className="text-lg">Files</CardTitle>
                <CardDescription>
                  {currentPath ? `Path: ${currentPath}` : "Root directory"}
                </CardDescription>
              </div>
              <div className="flex items-center gap-2">
                <Button variant="outline" size="sm" onClick={handleRefresh} disabled={loading}>
                  <RefreshCw className="h-4 w-4 mr-2" />
                  Refresh
                </Button>
                <Button size="sm" onClick={() => setUploadOpen(true)}>
                  <Upload className="h-4 w-4 mr-2" />
                  Upload
                </Button>
              </div>
            </div>
            <nav className="mt-2 flex items-center gap-1 text-sm text-muted-foreground" aria-label="Breadcrumb">
              <button
                type="button"
                onClick={() => handleNavigate("")}
                className="hover:text-foreground"
              >
                Root
              </button>
              {breadcrumbSegments.map((seg, i) => {
                const pathUpToHere = breadcrumbSegments.slice(0, i + 1).join("/");
                const isLast = i === breadcrumbSegments.length - 1;
                return (
                  <span key={pathUpToHere} className="flex items-center gap-1">
                    <ChevronRight className="h-4 w-4" />
                    {isLast ? (
                      <span className="text-foreground font-medium">{seg}</span>
                    ) : (
                      <button
                        type="button"
                        onClick={() => handleNavigate(pathUpToHere)}
                        className="hover:text-foreground"
                      >
                        {seg}
                      </button>
                    )}
                  </span>
                );
              })}
            </nav>
          </CardHeader>
          <CardContent>
            <FileBrowser
              items={items}
              currentPath={currentPath}
              loading={loading}
              selectedPath={selectedPath ?? undefined}
              onNavigate={handleNavigate}
              onRefresh={handleRefresh}
              onSelect={(item) => setSelectedPath(item.path)}
              onDownload={handleDownload}
              onDelete={handleDelete}
              onRename={openRename}
              onMove={openMove}
              onPreview={(item) => setPreviewItem(item)}
            />
            {total > ITEMS_PER_PAGE && (
              <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                <span>
                  Page {page} of {Math.ceil(total / ITEMS_PER_PAGE)}
                </span>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => p - 1)}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= Math.ceil(total / ITEMS_PER_PAGE)}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    Next
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <UploadDialog
        open={uploadOpen}
        onOpenChange={setUploadOpen}
        currentPath={currentPath}
        maxUploadSizeBytes={storageSettings.max_upload_size}
        allowedFileTypes={storageSettings.allowed_file_types}
        onUploadComplete={loadList}
      />

      <Dialog open={renameOpen} onOpenChange={setRenameOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Rename</DialogTitle>
            <DialogDescription>Enter the new name (no path).</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="rename-name">Name</Label>
            <Input
              id="rename-name"
              value={renameName}
              onChange={(e) => setRenameName(e.target.value)}
              placeholder="filename.txt"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRenameOpen(false)}>
              Cancel
            </Button>
            <Button onClick={submitRename} disabled={!renameName.trim() || renameSubmitting}>
              {renameSubmitting ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
              Rename
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={moveOpen} onOpenChange={setMoveOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Move</DialogTitle>
            <DialogDescription>Enter the destination directory path (empty for root).</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="move-dest">Destination path</Label>
            <Input
              id="move-dest"
              value={moveDestination}
              onChange={(e) => setMoveDestination(e.target.value)}
              placeholder="folder or folder/subfolder"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setMoveOpen(false)}>
              Cancel
            </Button>
            <Button onClick={submitMove} disabled={moveSubmitting}>
              {moveSubmitting ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
              Move
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {previewItem && (
        <FilePreview
          item={previewItem}
          open={!!previewItem}
          onOpenChange={(open) => !open && setPreviewItem(null)}
          onDownload={() => handleDownload(previewItem.path)}
        />
      )}
    </>
  );
}
