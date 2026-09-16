<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\FileDeletionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileController extends Controller
{
    private const SORTABLE_COLUMNS = ['created_at', 'expires_at', 'size_bytes'];

    public function index(Request $request): View
    {
        $sortBy = in_array($request->query('sort_by'), self::SORTABLE_COLUMNS, true)
            ? $request->query('sort_by')
            : 'created_at';

        $order = strtolower((string) $request->query('order')) === 'asc' ? 'asc' : 'desc';

        $perPage = (int) $request->query('per_page', 15);
        $perPage = ($perPage >= 1 && $perPage <= 100) ? $perPage : 15;

        $files = File::orderBy($sortBy, $order)
            ->paginate($perPage)
            ->appends($request->query());

        return view('files.index', compact('files', 'sortBy', 'order'));
    }

    public function destroy(File $file, FileDeletionService $fileDeletionService): JsonResponse
    {
        $result = $fileDeletionService->delete($file->id, 'manual', 'local');

        return response()->json(['success' => $result], $result ? 200 : 500);
    }
}
