<?php

namespace App\Http\Requests;

use App\Rules\ValidDocumentIntegrity;
use App\Rules\ValidFilenameEncoding;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'min:1',
                'max:'.config('files.max_size_kb'),
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                new ValidFilenameEncoding,
                new ValidDocumentIntegrity,
            ],
        ];
    }

    public function messages(): array
    {
        $maxMb = (int) (config('files.max_size_kb') / 1024);

        return [
            'file.max' => "File size must not exceed {$maxMb} MB.",
            'file.mimetypes' => 'The file type does not match the expected MIME type.',
        ];
    }
}
