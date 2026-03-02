<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailAttachment;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailAttachmentController extends Controller
{
    public function __construct(
        private StorageService $storageService,
    ) {}

    public function download(Request $request, EmailAttachment $emailAttachment): StreamedResponse
    {
        // Verify user owns this attachment's email
        if ($emailAttachment->email->user_id !== $request->user()->id) {
            abort(404);
        }

        return $this->storageService->downloadFile(
            $emailAttachment->storage_path,
            $emailAttachment->filename,
        );
    }
}
