<?php

namespace App\Actions\BusinessSettings;

use App\Models\BusinessSetting;
use App\Models\Quotation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class UpdateBusinessSettings
{
    /** @param array<string, mixed> $data */
    public function handle(
        BusinessSetting $settings,
        array $data,
        ?UploadedFile $logo,
        bool $removeLogo,
    ): BusinessSetting {
        $oldLogoPath = $settings->logo_path;
        $newLogoPath = null;

        if ($logo !== null) {
            $stored = $logo->storePublicly(
                "business-logos/{$settings->organization_id}",
                ['disk' => 'public'],
            );

            if ($stored === false) {
                throw new RuntimeException('The business logo could not be stored.');
            }

            $newLogoPath = $stored;
        }

        $attributes = Arr::except($data, ['logo', 'remove_logo']);

        if ($newLogoPath !== null) {
            $attributes['logo_path'] = $newLogoPath;
        } elseif ($removeLogo) {
            $attributes['logo_path'] = null;
        }

        try {
            $settings->update($attributes);
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }

        if ($oldLogoPath !== null && $oldLogoPath !== $settings->logo_path) {
            $this->deleteManagedLogoWhenUnused($settings, $oldLogoPath);
        }

        return $settings->refresh();
    }

    private function deleteManagedLogoWhenUnused(BusinessSetting $settings, string $path): void
    {
        if (! str_starts_with($path, "business-logos/{$settings->organization_id}/")) {
            return;
        }

        $isHistoricalSnapshot = Quotation::query()
            ->where('organization_id', $settings->organization_id)
            ->where('business_logo_path', $path)
            ->exists();

        if (! $isHistoricalSnapshot) {
            Storage::disk('public')->delete($path);
        }
    }
}
