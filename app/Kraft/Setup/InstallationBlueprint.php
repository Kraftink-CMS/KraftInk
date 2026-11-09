<?php

namespace App\Kraft\Setup;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

class InstallationBlueprint extends Blueprint
{
    public function __construct(
        Connection $connection,
        $table,
        ?Closure $callback,
        private readonly InstallationJournal $journal,
    ) {
        parent::__construct($connection, $table, $callback);
    }

    public function build(): void
    {
        $this->journal->apply($this->connection, $this->getTable(), $this->toSql(), $this->creating());
    }
}
