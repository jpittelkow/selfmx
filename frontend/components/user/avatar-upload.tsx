"use client";

import { useRef, useState, useEffect } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { getErrorMessage, getInitials } from "@/lib/utils";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import { Camera, Pencil, Trash2, Loader2 } from "lucide-react";
import type { User } from "@/lib/auth";

const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2MB
const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/gif", "image/webp"];
const AVATAR_MAX_DIM = 512; // Max width/height after resize (2x retina for 256px display)

/**
 * Resize an image file to fit within maxDim x maxDim using Canvas.
 * Returns a new File with the resized image as JPEG.
 */
function resizeImage(file: File, maxDim: number): Promise<File> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      let { width, height } = img;
      if (width <= maxDim && height <= maxDim) {
        URL.revokeObjectURL(img.src);
        resolve(file);
        return;
      }
      const scale = Math.min(maxDim / width, maxDim / height);
      width = Math.round(width * scale);
      height = Math.round(height * scale);
      const canvas = document.createElement("canvas");
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext("2d");
      if (!ctx) { reject(new Error("Canvas not supported")); return; }
      ctx.drawImage(img, 0, 0, width, height);
      canvas.toBlob(
        (blob) => {
          URL.revokeObjectURL(img.src);
          if (!blob) { reject(new Error("Resize failed")); return; }
          resolve(new File([blob], file.name.replace(/\.\w+$/, ".jpg"), { type: "image/jpeg" }));
        },
        "image/jpeg",
        0.85,
      );
    };
    img.onerror = () => { URL.revokeObjectURL(img.src); reject(new Error("Failed to load image")); };
    img.src = URL.createObjectURL(file);
  });
}

interface AvatarUploadProps {
  user: User | null;
  onAvatarUpdated: () => void;
}

export function AvatarUpload({ user, onAvatarUpdated }: AvatarUploadProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  // Revoke object URL on unmount to prevent memory leaks
  useEffect(() => {
    return () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl);
    };
  }, [previewUrl]);

  const displayUrl = previewUrl || user?.avatar || undefined;

  const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Reset input so same file can be re-selected
    e.target.value = "";

    if (!ACCEPTED_TYPES.includes(file.type)) {
      toast.error("Please select a valid image file (JPEG, PNG, GIF, or WebP)");
      return;
    }

    if (file.size > MAX_FILE_SIZE) {
      toast.error("Image must be less than 2MB");
      return;
    }

    setIsUploading(true);
    let objectUrl: string | null = null;

    try {
      // Resize large images client-side before uploading
      const resized = await resizeImage(file, AVATAR_MAX_DIM);

      // Show immediate preview
      objectUrl = URL.createObjectURL(resized);
      setPreviewUrl(objectUrl);

      const formData = new FormData();
      formData.append("avatar", resized);

      await api.post("/profile/avatar", formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      toast.success("Avatar updated");
      onAvatarUpdated();
    } catch (error: unknown) {
      // Revert preview on failure
      setPreviewUrl(null);
      toast.error(getErrorMessage(error, "Failed to upload avatar"));
    } finally {
      setIsUploading(false);
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    }
  };

  const handleDelete = async () => {
    setIsDeleting(true);
    try {
      await api.delete("/profile/avatar");
      setPreviewUrl(null);
      toast.success("Avatar removed");
      onAvatarUpdated();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to remove avatar"));
    } finally {
      setIsDeleting(false);
    }
  };

  const triggerFileInput = () => {
    fileInputRef.current?.click();
  };

  return (
    <div className="flex flex-col items-center text-center rounded-xl bg-muted/30 py-8 px-6">
      <div className="relative group">
        <Avatar className="h-28 w-28">
          <AvatarImage src={displayUrl} />
          <AvatarFallback className="text-2xl">
            {user?.name ? getInitials(user.name) : "?"}
          </AvatarFallback>
        </Avatar>

        {/* Desktop hover overlay */}
        <button
          type="button"
          onClick={triggerFileInput}
          disabled={isUploading}
          className="absolute inset-0 rounded-full bg-black/50 items-center justify-center cursor-pointer hidden md:group-hover:flex transition-opacity"
          aria-label="Change avatar"
        >
          {isUploading ? (
            <Loader2 className="h-6 w-6 text-white animate-spin" />
          ) : (
            <div className="flex flex-col items-center gap-1">
              <Camera className="h-6 w-6 text-white" />
              <span className="text-xs text-white font-medium">Change</span>
            </div>
          )}
        </button>

        {/* Mobile edit button - always visible */}
        <Button
          type="button"
          variant="secondary"
          size="icon"
          onClick={triggerFileInput}
          disabled={isUploading}
          className="absolute -bottom-1 -right-1 h-8 w-8 rounded-full shadow-md md:hidden"
          aria-label="Change avatar"
        >
          {isUploading ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
          ) : (
            <Pencil className="h-3.5 w-3.5" />
          )}
        </Button>
      </div>

      <input
        ref={fileInputRef}
        type="file"
        accept="image/jpeg,image/png,image/gif,image/webp"
        onChange={handleFileSelect}
        className="hidden"
        aria-hidden="true"
      />

      <p className="mt-4 text-xl font-semibold">{user?.name}</p>
      <p className="text-sm text-muted-foreground">{user?.email}</p>

      {/* Remove avatar button - only shown when avatar exists */}
      {user?.avatar && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={handleDelete}
          disabled={isDeleting}
          className="mt-3 text-muted-foreground hover:text-destructive"
        >
          {isDeleting ? (
            <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
          ) : (
            <Trash2 className="mr-1.5 h-3.5 w-3.5" />
          )}
          Remove photo
        </Button>
      )}
    </div>
  );
}
