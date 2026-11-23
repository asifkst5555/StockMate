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

        foreach ($collection as $key => $row) {
            try {
                DB::beginTransaction();

                $email = trim((string) ($row[1] ?? ''));
                $supplierEmail = Supplier::whereEmail($email)->exists();
                if ($supplierEmail) {
                    throw new UnprocessableEntityHttpException('Email '.$email.' is already exist.');
                }

                if (preg_match("/^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/ix", $email) == 0) {
                    throw new UnprocessableEntityHttpException('Email '.$email.' is not the valid email address.');
                }

                $supplierData = [
                    'name' => trim((string) $row[0]),
                    'email' => $email,
                    'phone' => trim((string) $row[2]),
                    'country' => trim((string) $row[3]),
                    'city' => trim((string) $row[4]),
                    'address' => trim((string) $row[5]),
                ];

                Supplier::create($supplierData);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw new UnprocessableEntityHttpException($e->getMessage());
            }
        }
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
            '1' => 'required',
            '2' => 'required|numeric',
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
