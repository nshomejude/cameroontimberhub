<?php

namespace App\Support\Bus;

interface HandlesQuery
{
    public function handle(Query $query): mixed;
}
