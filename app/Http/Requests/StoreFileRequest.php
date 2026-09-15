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
                'max:10240',
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                new ValidFilenameEncoding,
                new ValidDocumentIntegrity,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes'    => 'Only PDF & DOCX files are allowed.',
            'file.max'      => 'File size must not exceed 10 MB.', // TODO: Replace with config later
            'file.mimetypes'=> 'The file type does not match the expected MIME type.',
        ];
    }
}
