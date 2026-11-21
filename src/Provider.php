<?php

namespace ElChoco\RepoSync;

use Flarum\Foundation\AbstractServiceProvider;
use GuzzleHttp\Client;

final class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(GitHub::class, fn ($container) => new GitHub(new Client(), $container->make(Configuration::class)));
    }
}
