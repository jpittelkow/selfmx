<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    use ApiResponseTrait;

    /**
     * Get branding settings (public - no auth required).
     */
    public function show(): JsonResponse
    {
        $settings = SystemSetting::where('group', 'branding')
            ->where('is_public', true)
            ->get()
            ->pluck('value', 'key');

        return $this->dataResponse([
            'settings' => $settings,
        ]);
    }

    /**
     * Update branding settings (admin only).
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logo_url' => ['sometimes', 'nullable', 'string'],
            'logo_url_dark' => ['sometimes', 'nullable', 'string'],
            'favicon_url' => ['sometimes', 'nullable', 'string'],
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_theme' => ['sometimes', 'nullable', 'string', 'max:50'],
            'dark_mode_default' => ['sometimes', 'boolean'],
            'custom_css' => ['sometimes', 'nullable', 'string'],
        ]);

        $user = $request->user();

        foreach ($validated as $key => $value) {
            // Logo, favicon URLs, colors, and custom CSS are public
            $isPublic = in_array($key, ['logo_url', 'logo_url_dark', 'favicon_url', 'primary_color', 'secondary_color', 'color_theme', 'dark_mode_default', 'custom_css']);

            SystemSetting::set($key, $value, 'branding', $user->id, $isPublic);
        }

        return $this->successResponse('Branding settings updated successfully');
    }

    /**
     * Upload logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logo' => ['required', 'image', 'max:2048'], // 2MB max
        ]);

        try {
            // Get current logo URL before uploading new one
            $currentUrl = SystemSetting::get('logo_url', null, 'branding');
            if ($currentUrl) {
                // Extract filename from URL (e.g., /storage/branding/filename.png -> filename.png)
                $oldFilename = basename($currentUrl);
                if ($oldFilename) {
                    // Delete old file from storage
                    Storage::disk('public')->delete('branding/' . $oldFilename);
                }
            }

            $path = $request->file('logo')->store('branding', 'public');
            // Use relative URL to avoid port mismatch issues
            $url = '/storage/' . $path;

            $user = $request->user();
            SystemSetting::set('logo_url', $url, 'branding', $user->id, true);

            return $this->successResponse('Logo uploaded successfully', ['url' => $url]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to upload logo: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Upload dark mode logo.
     */
    public function uploadLogoDark(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logo' => ['required', 'image', 'max:2048'], // 2MB max
        ]);

        try {
            // Get current dark logo URL before uploading new one
            $currentUrl = SystemSetting::get('logo_url_dark', null, 'branding');
            if ($currentUrl) {
                $oldFilename = basename($currentUrl);
                if ($oldFilename) {
                    Storage::disk('public')->delete('branding/' . $oldFilename);
                }
            }

            $path = $request->file('logo')->store('branding', 'public');
            // Use relative URL to avoid port mismatch issues
            $url = '/storage/' . $path;

            $user = $request->user();
            SystemSetting::set('logo_url_dark', $url, 'branding', $user->id, true);

            return $this->successResponse('Dark mode logo uploaded successfully', ['url' => $url]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to upload dark mode logo: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Upload favicon.
     */
    public function uploadFavicon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'favicon' => ['required', 'image', 'max:512'], // 512KB max for favicon
        ]);

        try {
            // Get current favicon URL before uploading new one
            $currentUrl = SystemSetting::get('favicon_url', null, 'branding');
            if ($currentUrl) {
                // Extract filename from URL (e.g., /storage/branding/filename.ico -> filename.ico)
                $oldFilename = basename($currentUrl);
                if ($oldFilename) {
                    // Delete old file from storage
                    Storage::disk('public')->delete('branding/' . $oldFilename);
                }
            }

            $path = $request->file('favicon')->store('branding', 'public');
            // Use relative URL to avoid port mismatch issues
            $url = '/storage/' . $path;

            $user = $request->user();
            SystemSetting::set('favicon_url', $url, 'branding', $user->id, true);

            return $this->successResponse('Favicon uploaded successfully', ['url' => $url]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to upload favicon: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete dark mode logo.
     */
    public function deleteLogoDark(Request $request): JsonResponse
    {
        try {
            $currentUrl = SystemSetting::get('logo_url_dark', null, 'branding');
            if ($currentUrl) {
                $filename = basename($currentUrl);
                if ($filename) {
                    Storage::disk('public')->delete('branding/' . $filename);
                }
            }

            $user = $request->user();
            SystemSetting::set('logo_url_dark', null, 'branding', $user->id, true);

            return $this->deleteResponse('Dark mode logo deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete dark mode logo: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete logo.
     */
    public function deleteLogo(Request $request): JsonResponse
    {
        try {
            $currentUrl = SystemSetting::get('logo_url', null, 'branding');
            if ($currentUrl) {
                // Extract filename from URL and delete from storage
                $filename = basename($currentUrl);
                if ($filename) {
                    Storage::disk('public')->delete('branding/' . $filename);
                }
            }

            // Clear logo URL from database
            $user = $request->user();
            SystemSetting::set('logo_url', null, 'branding', $user->id, true);

            return $this->deleteResponse('Logo deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete logo: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete favicon.
     */
    public function deleteFavicon(Request $request): JsonResponse
    {
        try {
            $currentUrl = SystemSetting::get('favicon_url', null, 'branding');
            if ($currentUrl) {
                // Extract filename from URL and delete from storage
                $filename = basename($currentUrl);
                if ($filename) {
                    Storage::disk('public')->delete('branding/' . $filename);
                }
            }

            // Clear favicon URL from database
            $user = $request->user();
            SystemSetting::set('favicon_url', null, 'branding', $user->id, true);

            return $this->deleteResponse('Favicon deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete favicon: ' . $e->getMessage(), 500);
        }
    }
}
