<?php

namespace App\Models;

use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable line of an order — a copy of the accepted quote's line, taken
 * at award time. Editing the quote item afterwards changes nothing here.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    /** The species as it was named at award time, not as it is named today. */
    public function speciesLabel(): ?string
    {
        return $this->species_name;
    }

    public function formLabel(): ?string
    {
        return $this->form ? (TimberForm::tryFrom($this->form)?->label() ?? $this->form) : null;
    }

    public function unitLabel(): string
    {
        return RfqUnit::tryFrom($this->unit)?->label() ?? $this->unit;
    }
}
