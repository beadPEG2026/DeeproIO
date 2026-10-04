<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Articles extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('articles', function (Blueprint $table) {

            // Fields
            $table->increments('id');
            $table->string('title', 254);
            $table->longText('body');
            $table->fullText('body');
            $table->string('slug', 254)->index();
            $table->integer('language')->nullable()->index();
            $table->bigInteger('file_id')->unsigned()->nullable();
            $table->integer('category_id')->index();

            // Currency status
            $table->boolean('status')->default(true);
            $table->boolean('featured')->default(false);

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('file_uploads')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('articles');
    }
}
