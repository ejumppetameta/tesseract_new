<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateModelEvaluationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('model_evaluations', function (Blueprint $table) {
            $table->id();  // Auto-incrementing primary key.
            $table->string('evaluation_type'); // e.g. "Category Classification Report"
            $table->text('report');            // Full classification report string.
            $table->timestamps();              // created_at and updated_at columns.
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('model_evaluations');
    }
}
