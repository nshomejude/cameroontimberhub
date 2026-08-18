<?php

namespace App\Models;

use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'form' => TimberForm::class,
            'unit' => RfqUnit::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function rfqItem(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    /** Human specification line: "50 x 150 x 3000mm · Grade A · Kiln dried". */
    public function specification(): string
    {
        return collect([$this->dimensions, $this->grade, $this->form?->label()])
            ->filter()
            ->implode(' · ');
    }
}
