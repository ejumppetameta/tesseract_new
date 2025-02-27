<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_id',
        'transaction_date',
        'description',
        'debit',
        'credit',
        'balance',
    ];

    public function bankStatement()
    {
        return $this->belongsTo(BankStatement::class);
    }
}
