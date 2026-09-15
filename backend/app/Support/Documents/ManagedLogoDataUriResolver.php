<?php

namespace App\Support\Documents;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ManagedLogoDataUriResolver
{
    public function resolve(?string $path, int $organizationId): ?string
    {
        $prefix = "business-logos/{$organizationId}/";

        if ($path === null || ! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path)) {
                return null;
            }

            $mimeType = $this->supportedImageMimeType($disk, $path);

            if ($mimeType === null) {
                return null;
            }

            return 'data:'.$mimeType.';base64,'.base64_encode($disk->get($path));
        } catch (Throwable) {
            return null;
        }
    }

    private function supportedImageMimeType(FilesystemAdapter $disk, string $path): ?string
    {
        $mimeType = $disk->mimeType($path);

        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            ? $mimeType
            : null;
    }
}
