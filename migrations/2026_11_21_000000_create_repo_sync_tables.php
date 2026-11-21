<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->create('repo_sync_jobs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('job_key', 64)->unique();
            $table->string('mapping_id', 40)->index();
            $table->string('mapping_hash', 64);
            $table->string('repo', 180);
            $table->string('kind', 10);
            $table->string('release_id', 24)->nullable();
            $table->boolean('initialize')->default(false);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('result', 40)->nullable();
            $table->unsignedInteger('post_id')->nullable();
            $table->dateTime('available_at')->index();
            $table->dateTime('locked_at')->nullable();
            $table->string('lock_token', 40)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('repo_sync_backups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('mapping_id', 40)->index();
            $table->unsignedInteger('post_id')->index();
            $table->mediumText('content');
            $table->dateTime('created_at');
        });
    },
    'down' => function (Builder $schema): void {
        $schema->dropIfExists('repo_sync_backups');
        $schema->dropIfExists('repo_sync_jobs');
    },
];
