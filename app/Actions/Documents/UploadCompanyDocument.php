<?php

namespace App\Actions\Documents;

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Http\UploadedFile;

class UploadCompanyDocument
{
    public function __construct(private readonly DocumentService $documents) {}

    public function execute(Company $company, DocumentType $type, UploadedFile $file, ?User $uploader = null, array $attributes = []): CompanyDocument
    {
        return $this->documents->store($company, $type, $file, $uploader, $attributes);
    }
}
