<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\VersionService;
use Illuminate\Http\JsonResponse;

class VersionController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        private VersionService $versionService
    ) {}

    /**
     * Get version information.
     */
    public function index(): JsonResponse
    {
        return $this->dataResponse($this->versionService->getVersionInfo());
    }
}
