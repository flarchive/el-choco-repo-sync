<?php

use ElChoco\RepoSync\AdminController;
use ElChoco\RepoSync\RunCommand;
use ElChoco\RepoSync\WebhookController;
use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js')->css(__DIR__.'/less/admin.less'),
    (new Extend\Routes('api'))
        ->post('/repo-sync/webhook', 'el-choco.repo-sync.webhook', WebhookController::class)
        ->get('/repo-sync/admin', 'el-choco.repo-sync.admin', AdminController::class)
        ->post('/repo-sync/admin', 'el-choco.repo-sync.action', AdminController::class),
    (new Extend\Csrf())->exemptRoute('el-choco.repo-sync.webhook'),
    (new Extend\Console())->command(RunCommand::class)
        ->schedule(RunCommand::class, fn ($event) => $event->everyMinute()->withoutOverlapping()),
    (new Extend\Formatter())->configure(function ($configurator) {
        $configurator->BBCodes->addCustom('[REPOSYNC]{TEXT}[/REPOSYNC]', '<div class="RepoSync-content">{TEXT}</div>');
    }),
    new Extend\Locales(__DIR__.'/locale'),
    (new Extend\ServiceProvider())->register(ElChoco\RepoSync\Provider::class),
];
