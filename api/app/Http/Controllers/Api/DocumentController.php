<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    private const CATEGORIES = [
        'photograph',
        'birth_certificate',
        'transfer_certificate',
        'identity_document',
        'qualification',
        'appointment_letter',
        'other',
    ];

    public function uploadStudent(Request $request, Student $student): JsonResponse
    {
        $data = $this->validatedUpload($request);
        $file = $data['document'];
        $path = $file->store("students/{$student->id}", 'local');
        try {
            $document = DB::transaction(fn () => $student->documents()->create([
                'category' => $data['category'],
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'stored_path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $request->user()->id,
            ]));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        AuditLog::record('student.document_uploaded', $student, [
            'document_id' => $document->id,
            'category' => $document->category,
            'size_bytes' => $document->size_bytes,
        ]);

        return response()->json(['data' => $document], 201);
    }

    public function downloadStudent(StudentDocument $document): StreamedResponse
    {
        return $this->download($document->stored_path, $document->original_name, $document->mime_type);
    }

    public function uploadEmployee(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validatedUpload($request);
        $file = $data['document'];
        $path = $file->store("employees/{$employee->id}", 'local');
        try {
            $document = DB::transaction(fn () => $employee->documents()->create([
                'category' => $data['category'],
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'stored_path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $request->user()->id,
            ]));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        AuditLog::record('employee.document_uploaded', $employee, [
            'document_id' => $document->id,
            'category' => $document->category,
            'size_bytes' => $document->size_bytes,
        ]);

        return response()->json(['data' => $document], 201);
    }

    public function downloadEmployee(EmployeeDocument $document): StreamedResponse
    {
        return $this->download($document->stored_path, $document->original_name, $document->mime_type);
    }

    private function validatedUpload(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);
    }

    private function download(string $path, string $name, string $mimeType): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404, 'The requested document is no longer available.');

        return Storage::disk('local')->download($path, $name, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
