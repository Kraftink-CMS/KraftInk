<?php

namespace App\Kraft\Extensions\Contracts;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;

interface PrivacyHandler
{
    public function export(ExportingPersonalData $event): void;

    public function anonymize(AccountDeleting $event): void;
}
