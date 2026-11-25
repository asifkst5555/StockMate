<?php

namespace App\Imports;

use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SupplierImport implements ToCollection, WithChunkReading, WithStartRow, WithValidation
{
    public function collection(Collection $rows): void
    {
        $collection = $rows->toArray();

        foreach ($collection as $row) {
            $email = trim((string) ($row[1] ?? ''));
            if (Supplier::whereEmail($email)->exists()) {
                throw new UnprocessableEntityHttpException('Email ' . $email . ' is already exist.');
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new UnprocessableEntityHttpException('Email ' . $email . ' is not the valid email address.');
            }

            $supplierData = [
                'name' => $this->sanitizeCsvCell((string) ($row[0] ?? '')),
                'email' => $email,
                'phone' => trim((string) ($row[2] ?? '')),
                'country' => $this->sanitizeCsvCell((string) ($row[3] ?? '')),
                'city' => $this->sanitizeCsvCell((string) ($row[4] ?? '')),
                'address' => $this->sanitizeCsvCell((string) ($row[5] ?? '')),
            ];

            Supplier::create($supplierData);
        }
    }

    private function sanitizeCsvCell(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && preg_match('/^[=+\-@\t\r]/', $trimmed) === 1) {
            return "'" . $trimmed;
        }

        return $trimmed;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function startRow(): int
    {
        return 2;
    }

    public function rules(): array
    {
        return [
            '0' => 'required',
            '1' => 'required|email',
            '2' => ['required', 'regex:/^[0-9+\-\s().]{6,25}$/'],
            '3' => 'required',
            '4' => 'required',
            '5' => 'required',
        ];
    }

    public function customValidationMessages()
    {
        return [
            '0.required' => 'Name field is required',
            '1.required' => 'Email field is required',
            '2.required' => 'Phone Number field is required',
            '3.required' => 'Country field is required',
            '4.required' => 'City field is required',
            '5.required' => 'Address field is required',
        ];
    }
}
