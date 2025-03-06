<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->onDelete('cascade');
            
            // Common fields from both controllers
            $table->date('transaction_date');
            $table->string('description');
            $table->string('category')->nullable(); // From CreditSense
            $table->decimal('debit', 10, 2)->default(0);
            $table->decimal('credit', 10, 2)->default(0);
            $table->decimal('balance', 10, 2)->default(0);
            
            // Fields from original PdfOcrController
            $table->string('reference_number')->nullable();
            $table->string('dr_mydebit')->nullable();
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['bank_statement_id']);
        });
        Schema::dropIfExists('transactions');
    }
};