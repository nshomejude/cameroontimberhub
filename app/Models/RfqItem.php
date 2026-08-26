<?php

namespace App\Models;

use App\Enums\TimberForm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'form' => TimberForm::class,
            'quantity' => 'decimal:2',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function label(): string
    {
        $name = $this->species?->common_name ?: $this->species_text ?: 'Timber';

        // `form` is cast to TimberForm, so read its human label rather than
        // interpolating the enum object.
        $form = $this->form?->label() ?? '';

        return trim($name.' · '.$form.' · '.rtrim(rtrim((string) $this->quantity, '0'), '.').' '.$this->unit, ' ·');
    }
}
