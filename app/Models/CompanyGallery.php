<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class CompanyGallery extends Model
{
    use HasFactory;

    protected $table = 'company_gallery';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
            'is_portfolio' => 'boolean',
        ];
    }

    public function scopePortfolio(Builder $query): Builder
    {
        return $query->where('is_portfolio', true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function booted(): void
    {
        static::creating(function (CompanyGallery $image) {
            $company = $image->company_id ? Company::find($image->company_id) : null;

            if (! $company) {
                return;
            }

            $limit = $company->maxGalleryImages();

            if ($company->gallery()->count() >= $limit) {
                throw new RuntimeException("This company's plan allows a gallery image limit of {$limit} images. Upgrade the plan or remove an existing image before adding another.");
            }
        });
    }
}
