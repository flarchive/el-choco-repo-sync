<?php

namespace ElChoco\RepoSync;

use Flarum\Console\AbstractCommand;

final class RunCommand extends AbstractCommand
{
    public function __construct(private readonly Processor $processor) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('repo-sync:run')->setDescription('Process up to five pending Repo Sync jobs.');
    }

    protected function fire(): int
    {
        $this->output->writeln('Repo Sync: '.$this->processor->run().' job(s) processed.');
        return 0;
    }
}
