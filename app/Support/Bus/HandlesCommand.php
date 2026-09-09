<?php

namespace App\Support\Bus;

interface HandlesCommand
{
    public function handle(Command $command): mixed;
}
