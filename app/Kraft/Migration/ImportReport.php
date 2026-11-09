<?php

namespace App\Kraft\Migration;

use App\Models\Imports\ImportRecord;

final class ImportReport
{
    public function destinations(array $report, string $runId): array
    {
        $references = [];
        foreach ($report['notices'] ?? [] as $index => $notice) {
            $table = isset($notice['owner_type'])
                ? str_replace('.', '_', $notice['owner_type'])
                : $notice['table'] ?? null;
            $id = $notice['owner_id'] ?? ($notice['id'] ?? null);
            if (is_string($table) && (is_int($id) || is_string($id))) {
                $references[$table][(string) $id][] = $index;
            }
        }
        foreach ($references as $table => $positions) {
            foreach (array_chunk(array_map('strval', array_keys($positions)), 500) as $chunk) {
                foreach (
                    ImportRecord::query()
                        ->where('run_id', $runId)
                        ->where('source_table', $table)
                        ->whereIn('source_id', $chunk)
                        ->whereNotNull('target_table')
                        ->get(['source_id', 'target_table', 'target_id']) as $record
                ) {
                    /** @var ImportRecord $record */
                    $destination = [
                        'target_table' => $record->target_table,
                        'target_id' => $record->target_id,
                    ];
                    foreach ($positions[$record->source_id] ?? [] as $index) {
                        $report['notices'][$index] += $destination;
                    }
                }
            }
        }

        return $report;
    }

    public function guidance(array $notice): string
    {
        $kind = $notice['kind'] ?? 'unsupported';
        $reason = $notice['reason'] ?? '';
        $key = match (true) {
            $kind === 'media' => 'media',
            $kind === 'access_review',
            in_array(
                $reason,
                ['required_roles', 'required_products', 'unmapped_role', 'invalid_access_rules', 'restricted_tag'],
                true,
            ) => 'access',
            $kind === 'extension', $reason === 'extension_unavailable' => 'extension',
            $reason === 'missing_paid_amount' => 'missing_amount',
            in_array($reason, ['site_money_units', 'unsupported_currency', 'giftcard_face_value'], true) => 'currency',
            preg_match(
                '/command|server_choice|server_assignment|multiple_server|dynamic_server|target_game/',
                $reason,
            ) === 1 => 'commands',
            preg_match('/coupon|discount|upgrade|purchase_limit|expiration|billing|prerequisites/', $reason) === 1 => 'commercial',
            preg_match('/reconnect|credentials/', $reason) === 1 => 'reconnect',
            $reason === 'field_type' => 'field',
            $kind === 'configuration', $reason === 'manual_configuration' => 'configuration',
            default => 'unsupported',
        };

        return __('migration.guidance.'.$key);
    }
}
