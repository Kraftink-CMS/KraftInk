<?php

namespace App\Kraft\Migration\Contracts;

use Illuminate\Database\Connection;

interface ValidatesSource
{
    public function validateSource(Connection $source, array $options): array;
}
