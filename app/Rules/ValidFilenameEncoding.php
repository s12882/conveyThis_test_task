<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidFilenameEncoding implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        if (! mb_check_encoding($value->getClientOriginalName(), 'UTF-8')) {
            $fail('The file name contains invalid or unreadable characters.');
        }
    }
}
