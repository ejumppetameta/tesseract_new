<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_statement_id');
            $table->date('transaction_date')->nullable();
            $table->string('description')->nullable();
            $table->decimal('debit', 15, 2)->nullable();
            $table->decimal('credit', 15, 2)->nullable();
            $table->decimal('balance', 15, 2)->nullable();
            $table->timestamps();

            $table->foreign('bank_statement_id')->references('id')->on('bank_statements')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}

