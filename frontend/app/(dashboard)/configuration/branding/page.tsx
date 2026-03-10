"use client";

import { useState, useEffect, useCallback } from "react";
import Image from "next/image";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { getErrorMessage } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Textarea } from "@/components/ui/textarea";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { SaveButton } from "@/components/ui/save-button";
import { Loader2, Upload, RotateCcw, Trash2, Palette } from "lucide-react";
import { ColorPicker } from "@/components/ui/color-picker";
import { BrandingPreview } from "@/components/branding-preview";
import { HelpLink } from "@/components/help/help-link";
import { useAppConfig } from "@/lib/app-config";
import { ThemePicker } from "@/components/theme-picker";

const brandingSchema = z.object({
  logo_url: z.string().optional(),
  logo_url_dark: z.string().optional(),
  favicon_url: z.string().optional(),
  primary_color: z.string()
    .refine((val) => {
      if (!val || val === "") return true; // Empty is valid
      return /^#[0-9A-Fa-f]{6}$/.test(val);
    }, {
      message: "Must be a valid hex color (e.g., #3b82f6)",
    })
    .optional(),
  secondary_color: z.string()
    .refine((val) => {
      if (!val || val === "") return true; // Empty is valid
      return /^#[0-9A-Fa-f]{6}$/.test(val);
    }, {
      message: "Must be a valid hex color (e.g., #3b82f6)",
    })
    .optional(),
  custom_css: z.string().optional(),
});

type BrandingForm = z.infer<typeof brandingSchema>;

export default function BrandingSettingsPage() {
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isUploading, setIsUploading] = useState(false);
  const [isUploadingDarkLogo, setIsUploadingDarkLogo] = useState(false);
  const [isUploadingFavicon, setIsUploadingFavicon] = useState(false);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [logoDarkPreview, setLogoDarkPreview] = useState<string | null>(null);
  const [faviconPreview, setFaviconPreview] = useState<string | null>(null);
  const queryClient = useQueryClient();
  const { appName } = useAppConfig();

  const { register, handleSubmit, formState: { errors, isDirty }, setValue, watch, reset } = useForm<BrandingForm>({
    resolver: zodResolver(brandingSchema),
    mode: "onBlur", // Validate on blur to avoid blocking while typing
    defaultValues: {
      logo_url: "",
      logo_url_dark: "",
      favicon_url: "",
      primary_color: "",
      secondary_color: "",
      custom_css: "",
    },
  });

  const logoUrl = watch("logo_url");
  const logoDarkUrl = watch("logo_url_dark");
  const faviconUrl = watch("favicon_url");

  const fetchSettings = useCallback(async () => {
    setIsLoading(true);
    try {
      const response = await api.get("/branding");
      const settings = response.data.settings || {};

      // Sanitize: treat the literal string "null" (a backend serialization artifact) as empty
      const clean = (v: unknown): string => (typeof v === "string" && v !== "null") ? v : "";

      // Reset form with fetched values (this sets them as the new default state)
      // Using reset() ensures isDirty works correctly by establishing new default values
      const formValues = {
        logo_url: clean(settings.logo_url),
        logo_url_dark: clean(settings.logo_url_dark),
        favicon_url: clean(settings.favicon_url),
        primary_color: clean(settings.primary_color),
        secondary_color: clean(settings.secondary_color),
        custom_css: clean(settings.custom_css),
      };
      
      reset(formValues);

      if (formValues.logo_url) {
        setLogoPreview(formValues.logo_url);
      }
      if (formValues.logo_url_dark) {
        setLogoDarkPreview(formValues.logo_url_dark);
      }
      if (formValues.favicon_url) {
        setFaviconPreview(formValues.favicon_url);
      }
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to load branding settings"));
    } finally {
      setIsLoading(false);
    }
  }, [reset]);

  useEffect(() => {
    fetchSettings();
  }, [fetchSettings]);

  useEffect(() => {
    if (logoUrl) {
      setLogoPreview(logoUrl);
    } else {
      setLogoPreview(null);
    }
  }, [logoUrl]);

  useEffect(() => {
    if (logoDarkUrl) {
      setLogoDarkPreview(logoDarkUrl);
    } else {
      setLogoDarkPreview(null);
    }
  }, [logoDarkUrl]);

  useEffect(() => {
    if (faviconUrl) {
      setFaviconPreview(faviconUrl);
    } else {
      setFaviconPreview(null);
    }
  }, [faviconUrl]);

  const onSubmit = async (data: BrandingForm) => {
    setIsSaving(true);
    try {
      // Filter out empty strings - send undefined/null instead so backend treats them as "clear this field"
      const payload = {
        ...data,
        logo_url: data.logo_url || null,
        logo_url_dark: data.logo_url_dark || null,
        favicon_url: data.favicon_url || null,
        primary_color: data.primary_color || null,
        secondary_color: data.secondary_color || null,
        custom_css: data.custom_css || null,
      };
      
      await api.put("/branding", payload);
      toast.success("Branding settings updated successfully");
      // Invalidate app-config cache so logo updates immediately
      queryClient.invalidateQueries({ queryKey: ["app-config"] });
      await fetchSettings();
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to update branding settings"));
    } finally {
      setIsSaving(false);
    }
  };

  const handleLogoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast.error("Please select an image file");
      return;
    }

    if (file.size > 2 * 1024 * 1024) {
      toast.error("Image must be less than 2MB");
      return;
    }

    setIsUploading(true);
    try {
      const formData = new FormData();
      formData.append("logo", file);

      const response = await api.post("/branding/logo", formData, {
        headers: {
          "Content-Type": "multipart/form-data",
        },
      });

      setValue("logo_url", response.data.url, { shouldDirty: true });
      setLogoPreview(response.data.url);
      toast.success("Logo uploaded successfully");
      // Invalidate app-config cache so logo updates immediately
      queryClient.invalidateQueries({ queryKey: ["app-config"] });
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to upload logo"));
    } finally {
      setIsUploading(false);
    }
  };

  const handleDarkLogoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast.error("Please select an image file");
      return;
    }

    if (file.size > 2 * 1024 * 1024) {
      toast.error("Image must be less than 2MB");
      return;
    }

    setIsUploadingDarkLogo(true);
    try {
      const formData = new FormData();
      formData.append("logo", file);

      const response = await api.post("/branding/logo-dark", formData, {
        headers: {
          "Content-Type": "multipart/form-data",
        },
      });

      setValue("logo_url_dark", response.data.url, { shouldDirty: true });
      setLogoDarkPreview(response.data.url);
      toast.success("Dark mode logo uploaded successfully");
      queryClient.invalidateQueries({ queryKey: ["app-config"] });
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to upload dark mode logo"));
    } finally {
      setIsUploadingDarkLogo(false);
    }
  };

  const handleFaviconUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast.error("Please select an image file");
      return;
    }

    if (file.size > 512 * 1024) {
      toast.error("Favicon must be less than 512KB");
      return;
    }

    setIsUploadingFavicon(true);
    try {
      const formData = new FormData();
      formData.append("favicon", file);

      const response = await api.post("/branding/favicon", formData, {
        headers: {
          "Content-Type": "multipart/form-data",
        },
      });

      setValue("favicon_url", response.data.url, { shouldDirty: true });
      setFaviconPreview(response.data.url);
      toast.success("Favicon uploaded successfully");
      // Invalidate app-config cache so favicon updates immediately
      queryClient.invalidateQueries({ queryKey: ["app-config"] });
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Failed to upload favicon"));
    } finally {
      setIsUploadingFavicon(false);
    }
  };

  const handleResetDefaults = () => {
    setValue("primary_color", "#3b82f6", { shouldDirty: true });
    setValue("secondary_color", "#6366f1", { shouldDirty: true });
    setValue("logo_url", "", { shouldDirty: true });
    setValue("logo_url_dark", "", { shouldDirty: true });
    setValue("favicon_url", "", { shouldDirty: true });
    setLogoPreview(null);
    setLogoDarkPreview(null);
    setFaviconPreview(null);
    toast.success("Reset to default values");
  };

  const handleDeleteLogo = () => {
    setValue("logo_url", "", { shouldDirty: true });
    setLogoPreview(null);
    toast.success("Logo removed");
  };

  const handleDeleteDarkLogo = () => {
    setValue("logo_url_dark", "", { shouldDirty: true });
    setLogoDarkPreview(null);
    toast.success("Dark mode logo removed");
  };

  const handleDeleteFavicon = () => {
    setValue("favicon_url", "", { shouldDirty: true });
    setFaviconPreview(null);
    toast.success("Favicon removed");
  };

  const handleResetPrimaryColor = () => {
    setValue("primary_color", "", { shouldDirty: true });
  };

  const handleResetSecondaryColor = () => {
    setValue("secondary_color", "", { shouldDirty: true });
  };

  if (isLoading) {
    return <SettingsPageSkeleton />;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Theme & Branding</h1>
        <p className="text-muted-foreground mt-2">
          Customize the appearance of your application.{" "}
          <HelpLink articleId="branding" />
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Palette className="h-5 w-5" />
            Color Theme
          </CardTitle>
          <CardDescription>
            Set the color theme for all users.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ThemePicker mode="global" />
        </CardContent>
      </Card>

      <div className="grid gap-6 md:grid-cols-2">
        <form onSubmit={handleSubmit(onSubmit)}>
        <Card>
          <CardHeader>
            <CardTitle>Branding</CardTitle>
            <CardDescription>
              Configure logo, colors, and visual identity
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label>Logo</Label>
                <div className="flex items-center gap-4">
                  {logoPreview && (
                    <div className="relative group">
                      <div className="relative h-20 w-20">
                        <Image
                          src={logoPreview}
                          alt="Logo preview"
                          fill
                          className="object-contain border rounded"
                          unoptimized
                        />
                      </div>
                      <Button
                        type="button"
                        variant="destructive"
                        size="icon"
                        className="absolute -top-2 -right-2 h-6 w-6 transition-opacity"
                        onClick={handleDeleteLogo}
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    </div>
                  )}
                  <div className="flex-1">
                    <Input
                      type="file"
                      accept="image/*"
                      onChange={handleLogoUpload}
                      disabled={isUploading}
                      className="hidden"
                      id="logo-upload"
                    />
                    <Label
                      htmlFor="logo-upload"
                      className="cursor-pointer inline-flex items-center gap-2 px-4 py-2 border rounded-md hover:bg-muted"
                    >
                      {isUploading ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        <Upload className="h-4 w-4" />
                      )}
                      {isUploading ? "Uploading..." : "Upload Logo"}
                    </Label>
                    <p className="text-sm text-muted-foreground mt-2">
                      Or enter a URL
                    </p>
                  </div>
                </div>
                <Input
                  {...register("logo_url")}
                  placeholder="https://example.com/logo.png"
                />
                {errors.logo_url && (
                  <p className="text-sm text-destructive">
                    {errors.logo_url.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label>Dark Mode Logo</Label>
                <p className="text-sm text-muted-foreground">
                  Optional. Shown when dark mode is active. Falls back to the main logo if not set.
                </p>
                <div className="flex items-center gap-4">
                  {logoDarkPreview && (
                    <div className="relative group">
                      <div className="relative h-20 w-20 bg-gray-900 rounded">
                        <Image
                          src={logoDarkPreview}
                          alt="Dark mode logo preview"
                          fill
                          className="object-contain border rounded"
                          unoptimized
                        />
                      </div>
                      <Button
                        type="button"
                        variant="destructive"
                        size="icon"
                        className="absolute -top-2 -right-2 h-6 w-6 transition-opacity"
                        onClick={handleDeleteDarkLogo}
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    </div>
                  )}
                  <div className="flex-1">
                    <Input
                      type="file"
                      accept="image/*"
                      onChange={handleDarkLogoUpload}
                      disabled={isUploadingDarkLogo}
                      className="hidden"
                      id="dark-logo-upload"
                    />
                    <Label
                      htmlFor="dark-logo-upload"
                      className="cursor-pointer inline-flex items-center gap-2 px-4 py-2 border rounded-md hover:bg-muted"
                    >
                      {isUploadingDarkLogo ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        <Upload className="h-4 w-4" />
                      )}
                      {isUploadingDarkLogo ? "Uploading..." : "Upload Dark Logo"}
                    </Label>
                    <p className="text-sm text-muted-foreground mt-2">
                      Or enter a URL
                    </p>
                  </div>
                </div>
                <Input
                  {...register("logo_url_dark")}
                  placeholder="https://example.com/logo-dark.png"
                />
                {errors.logo_url_dark && (
                  <p className="text-sm text-destructive">
                    {errors.logo_url_dark.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label>Favicon</Label>
                <div className="flex items-center gap-4">
                  {faviconPreview && (
                    <div className="relative group">
                      <div className="relative h-16 w-16">
                        <Image
                          src={faviconPreview}
                          alt="Favicon preview"
                          fill
                          className="object-contain border rounded"
                          unoptimized
                        />
                      </div>
                      <Button
                        type="button"
                        variant="destructive"
                        size="icon"
                        className="absolute -top-2 -right-2 h-6 w-6 transition-opacity"
                        onClick={handleDeleteFavicon}
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    </div>
                  )}
                  <div className="flex-1">
                    <Input
                      type="file"
                      accept="image/*"
                      onChange={handleFaviconUpload}
                      disabled={isUploadingFavicon}
                      className="hidden"
                      id="favicon-upload"
                    />
                    <Label
                      htmlFor="favicon-upload"
                      className="cursor-pointer inline-flex items-center gap-2 px-4 py-2 border rounded-md hover:bg-muted"
                    >
                      {isUploadingFavicon ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        <Upload className="h-4 w-4" />
                      )}
                      {isUploadingFavicon ? "Uploading..." : "Upload Favicon"}
                    </Label>
                    <p className="text-sm text-muted-foreground mt-2">
                      Or enter a URL
                    </p>
                  </div>
                </div>
                <Input
                  {...register("favicon_url")}
                  placeholder="https://example.com/favicon.ico"
                />
                {errors.favicon_url && (
                  <p className="text-sm text-destructive">
                    {errors.favicon_url.message}
                  </p>
                )}
              </div>

              <Separator />

              <div className="space-y-2">
                <Label htmlFor="primary_color">Primary Color</Label>
                <div className="flex items-center gap-2">
                  <ColorPicker
                    value={watch("primary_color") || ""}
                    onChange={(color) => setValue("primary_color", color, { shouldDirty: true })}
                  />
                  {watch("primary_color") && (
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      onClick={handleResetPrimaryColor}
                      title="Reset to system default"
                    >
                      <RotateCcw className="h-4 w-4" />
                    </Button>
                  )}
                </div>
                <p className="text-sm text-muted-foreground">
                  Leave empty to use system default
                </p>
                {errors.primary_color && (
                  <p className="text-sm text-destructive">
                    {errors.primary_color.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="secondary_color">Secondary Color</Label>
                <div className="flex items-center gap-2">
                  <ColorPicker
                    value={watch("secondary_color") || ""}
                    onChange={(color) => setValue("secondary_color", color, { shouldDirty: true })}
                  />
                  {watch("secondary_color") && (
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      onClick={handleResetSecondaryColor}
                      title="Reset to system default"
                    >
                      <RotateCcw className="h-4 w-4" />
                    </Button>
                  )}
                </div>
                <p className="text-sm text-muted-foreground">
                  Leave empty to use system default
                </p>
                {errors.secondary_color && (
                  <p className="text-sm text-destructive">
                    {errors.secondary_color.message}
                  </p>
                )}
              </div>

              <Separator />

              <div className="space-y-2">
                <Label htmlFor="custom_css">Custom CSS</Label>
                <Textarea
                  id="custom_css"
                  {...register("custom_css")}
                  placeholder="/* Your custom CSS here */"
                  rows={10}
                  className="font-mono text-sm"
                />
                <p className="text-sm text-muted-foreground">
                  Add custom CSS to override default styles
                </p>
              </div>
            </div>
          </CardContent>
          <CardFooter className="flex justify-between">
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button
                  type="button"
                  variant="outline"
                  disabled={isSaving}
                >
                  <RotateCcw className="mr-2 h-4 w-4" />
                  Reset to Defaults
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Reset to Defaults</AlertDialogTitle>
                  <AlertDialogDescription>
                    This will reset your logo, colors, and custom CSS to defaults. You&apos;ll still need to save changes to apply the reset.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction onClick={handleResetDefaults}>
                    Reset
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
            <SaveButton isDirty={isDirty} isSaving={isSaving} />
          </CardFooter>
        </Card>
      </form>

      <BrandingPreview
        logoUrl={watch("logo_url") || undefined}
        primaryColor={watch("primary_color") || undefined}
        secondaryColor={watch("secondary_color") || undefined}
        appName={appName}
      />
      </div>
    </div>
  );
}
