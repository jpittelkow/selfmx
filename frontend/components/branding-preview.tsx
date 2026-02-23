"use client";

import Image from "next/image";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

interface BrandingPreviewProps {
  logoUrl?: string;
  primaryColor?: string;
  secondaryColor?: string;
  appName?: string;
}

export function BrandingPreview({
  logoUrl,
  primaryColor,
  secondaryColor,
  appName = "App Name",
}: BrandingPreviewProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Live Preview</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Header mock */}
        <div className="border rounded-lg p-4 space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              {logoUrl ? (
                <Image
                  src={logoUrl}
                  alt="Logo preview"
                  width={48}
                  height={24}
                  className="object-contain"
                  unoptimized
                />
              ) : (
                <div className="h-6 w-6 rounded-md text-white flex items-center justify-center text-xs font-bold" style={{ background: "linear-gradient(to right, #00f5ff, #7a5cff, #ff2bd6)" }}>
                  {appName.charAt(0).toUpperCase()}
                </div>
              )}
              <span className="text-sm font-semibold">{appName}</span>
            </div>
            <div className="h-8 w-8 rounded-full bg-muted" />
          </div>
        </div>

        {/* Button samples */}
        <div className="space-y-2">
          <p className="text-sm font-medium">Button Styles</p>
          <div className="flex gap-2 flex-wrap">
            <Button
              size="sm"
              style={
                primaryColor
                  ? {
                      backgroundColor: primaryColor,
                      color: getContrastColor(primaryColor),
                    }
                  : undefined
              }
            >
              Primary Button
            </Button>
            <Button
              variant="secondary"
              size="sm"
              style={
                secondaryColor
                  ? {
                      backgroundColor: secondaryColor,
                      color: getContrastColor(secondaryColor),
                    }
                  : undefined
              }
            >
              Secondary Button
            </Button>
          </div>
        </div>

        {/* Color swatches */}
        <div className="space-y-2">
          <p className="text-sm font-medium">Color Swatches</p>
          <div className="flex gap-2">
            {primaryColor && (
              <div className="flex-1 space-y-1">
                <div
                  className="h-12 rounded border"
                  style={{ backgroundColor: primaryColor }}
                />
                <p className="text-xs text-muted-foreground">Primary</p>
              </div>
            )}
            {secondaryColor && (
              <div className="flex-1 space-y-1">
                <div
                  className="h-12 rounded border"
                  style={{ backgroundColor: secondaryColor }}
                />
                <p className="text-xs text-muted-foreground">Secondary</p>
              </div>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

// Helper functions
function hexToHSL(hex: string): { h: number; s: number; l: number } {
  const cleanHex = hex.replace('#', '');
  const r = parseInt(cleanHex.substring(0, 2), 16) / 255;
  const g = parseInt(cleanHex.substring(2, 4), 16) / 255;
  const b = parseInt(cleanHex.substring(4, 6), 16) / 255;

  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  let h = 0;
  let s = 0;
  const l = (max + min) / 2;

  if (max !== min) {
    const d = max - min;
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);

    switch (max) {
      case r:
        h = ((g - b) / d + (g < b ? 6 : 0)) / 6;
        break;
      case g:
        h = ((b - r) / d + 2) / 6;
        break;
      case b:
        h = ((r - g) / d + 4) / 6;
        break;
    }
  }

  return {
    h: Math.round(h * 360),
    s: Math.round(s * 100),
    l: Math.round(l * 100),
  };
}

function getContrastColor(hex: string): string {
  const cleanHex = hex.replace('#', '');
  const r = parseInt(cleanHex.substring(0, 2), 16);
  const g = parseInt(cleanHex.substring(2, 4), 16);
  const b = parseInt(cleanHex.substring(4, 6), 16);
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance > 0.5 ? '#000000' : '#ffffff';
}
