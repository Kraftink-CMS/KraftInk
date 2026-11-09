<?php

namespace App\Kraft\Setup;

use App\Exceptions\InstallationFailure;
use PDOException;
use Throwable;

class DatabaseFailure
{
    public function translationKey(Throwable $exception, string $driver, bool $connected): string
    {
        for ($error = $exception; $error; $error = $error->getPrevious()) {
            if ($error instanceof InstallationFailure) {
                return $error->translationKey;
            }
            if (str_contains(strtolower($error->getMessage()), 'could not find driver')) {
                return 'system.install.requirements.db_driver';
            }
            if (!($error instanceof PDOException)) {
                continue;
            }
            $state = strtoupper((string) ($error->errorInfo[0] ?? $error->getCode()));
            $code = (int) ($error->errorInfo[1] ?? 0);
            if ($driver === 'sqlite') {
                if (!$connected || in_array($code & 255, [3, 8, 10, 13, 14, 26], true)) {
                    return 'system.install.requirements.db_sqlite';
                }

                continue;
            }
            if (str_starts_with($state, '28') || in_array($code, [1045, 1698], true)) {
                return 'system.install.requirements.db_auth';
            }
            if ($state === '3D000' || $code === 1049 || ($code === 1044 && !$connected)) {
                return 'system.install.requirements.db_missing';
            }
            if (str_starts_with($state, '08') || in_array($code, [2002, 2003, 2004, 2005, 2006, 2012, 2013], true)) {
                return 'system.install.requirements.db_unreachable';
            }
            if ($state === '42501' || in_array($code, [1044, 1142, 1143, 1227, 1370], true)) {
                return 'system.install.requirements.db_permission';
            }
        }

        return $driver === 'sqlite' && !$connected
            ? 'system.install.requirements.db_sqlite'
            : 'system.install.messages.failed';
    }
}
