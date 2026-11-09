<?php

namespace App\Kraft;

use App\Kraft\Extensions\Market;
use RuntimeException;
use Throwable;

class SiteAnnouncements extends RemoteFeed
{
    public function synchronize(): array
    {
        $source = $this->source();
        if ($source === null) {
            return [];
        }
        try {
            $this->accept($this->fetchFeed($source, 'url'));

            return [$source['id'] => true];
        } catch (Throwable) {
            return [$source['id'] => false];
        }
    }

    public function accept(mixed $input): void
    {
        $source = $this->source();
        if ($source === null) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $this->acceptFeed($source, 'announcements', $input);
    }

    public function active(): array
    {
        $source = $this->source();
        if ($source === null) {
            return [];
        }
        try {
            $document = $this->storedFeed($source['id'], 'announcements', $source);
            $announcements = [];
            foreach ($document['announcements'] ?? [] as $announcement) {
                if ($announcement['starts_at'] <= now()->timestamp && $announcement['expires_at'] > now()->timestamp) {
                    $announcements[] = [
                        'id' => $announcement['id'],
                        'source' => $source['name'],
                        'title' => app(Market::class)->localized($announcement['title']),
                        'body' => app(Market::class)->localized($announcement['body']),
                        'severity' => $announcement['severity'],
                        'url' => $announcement['url'] ?? null,
                    ];
                }
            }

            return $announcements;
        } catch (Throwable) {
            return [];
        }
    }

    private function source(): ?array
    {
        $settings = config('announcements', []);
        if (
            !is_array($settings) ||
            !($settings['enabled'] ?? true) ||
            !is_string($settings['id'] ?? null) ||
            !preg_match('/^[a-z][a-z0-9-]{0,49}$/D', $settings['id']) ||
            !is_string($settings['url'] ?? null) ||
            trim($settings['url']) === '' ||
            (isset($settings['signing_key']) &&
                (!is_string($settings['signing_key']) ||
                    trim($settings['signing_key']) === '' ||
                    strlen($settings['signing_key']) > 128))
        ) {
            return null;
        }

        return [
            'id' => $settings['id'],
            'url' => $settings['url'],
            'signing_key' => $settings['signing_key'] ?? null,
            'name' => $settings['name'] ?? (parse_url($settings['url'], PHP_URL_HOST) ?? $settings['id']),
        ];
    }

    protected function validateDocument(mixed $document): array
    {
        $document = parent::validateDocument($document);
        if (
            !is_array($document['announcements'] ?? null) ||
            !array_is_list($document['announcements']) ||
            count($document['announcements']) > 20
        ) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $ids = [];
        foreach ($document['announcements'] as $announcement) {
            if (
                !is_array($announcement) ||
                !is_string($announcement['id'] ?? null) ||
                !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $announcement['id']) ||
                isset($ids[$announcement['id']]) ||
                !in_array($announcement['severity'] ?? null, ['info', 'warning', 'danger'], true) ||
                !is_int($announcement['starts_at'] ?? null) ||
                $announcement['starts_at'] < 0 ||
                !is_int($announcement['expires_at'] ?? null) ||
                $announcement['expires_at'] <= $announcement['starts_at']
            ) {
                throw new RuntimeException(__('market.security.invalid'));
            }
            $ids[$announcement['id']] = true;
            $this->text($announcement['title'] ?? null, 160);
            $this->text($announcement['body'] ?? null, 4000);
            if (isset($announcement['url'])) {
                $url = $announcement['url'];
                $parts = is_string($url) ? parse_url($url) : null;
                if (
                    !is_array($parts) ||
                    strlen($url) > 2048 ||
                    ($parts['scheme'] ?? null) !== 'https' ||
                    empty($parts['host']) ||
                    !filter_var($url, FILTER_VALIDATE_URL) ||
                    preg_match('/[\x00-\x20\x7f"\'<>`\\\\]/', $url) ||
                    array_intersect(['user', 'pass', 'port'], array_keys($parts))
                ) {
                    throw new RuntimeException(__('market.security.invalid'));
                }
            }
        }

        return $document;
    }

    private function text(mixed $value, int $maximum): void
    {
        if (!is_array($value) || $value === [] || count($value) > 20) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        foreach ($value as $locale => $text) {
            if (
                !is_string($locale) ||
                !preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale) ||
                !is_string($text) ||
                preg_match('//u', $text) !== 1 ||
                trim($text) === '' ||
                mb_strlen($text) > $maximum
            ) {
                throw new RuntimeException(__('market.security.invalid'));
            }
        }
    }
}
