<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class ValidDocumentIntegrity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $isValid = match ($value->getMimeType()) {
            'application/pdf' => $this->hasPdfHeader($value->getRealPath()),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->isValidDocx($value->getRealPath()),
            default => true,
        };

        if (! $isValid) {
            $fail('The file is corrupted or is not a valid document.');
        }
    }

    private function hasPdfHeader(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if (! $handle) {
            return false;
        }

        $header = fread($handle, 5);
        fclose($handle);

        return $header === '%PDF-';
    }

    private function isValidDocx(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        return $hasContentTypes;
    }
}
