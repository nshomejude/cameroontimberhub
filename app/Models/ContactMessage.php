<?php

namespace App\Models;

use App\Models\Concerns\HasConsents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    use HasConsents, HasFactory;

    protected $guarded = ['id'];
}
