<?php

namespace App\Kraft\Events;

use App\Events\PluginEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;
use JsonException;

class PluginEvents
{
    private array $definitions = [];

    private array $listeners = [];

    private array $failures = [];

    public function define(string $name, int $version = 1): void
    {
        $this->validateName($name);
        if ($version < 1) {
            throw new InvalidArgumentException('Plugin event versions must be positive integers.');
        }
        $this->definitions[$name][$version] = true;
    }

    public function definitions(): array
    {
        return array_map(fn (array $versions) => array_keys($versions), $this->definitions);
    }

    public function listen(string $name, callable $listener, int $version = 1, ?string $id = null): void
    {
        if ($name !== '*') {
            $this->validateName($name);
        }
        if ($version < 1 || ($id !== null && (trim($id) === '' || strlen($id) > 180))) {
            throw new InvalidArgumentException('Invalid plugin event listener.');
        }
        $id ??= $name.':'.count($this->listeners[$name][$version] ?? []);
        $this->listeners[$name][$version][$id] = $listener;
    }

    /**
     * @throws JsonException
     */
    public function publish(string $name, array $data, string $id, int $version = 1): PluginEvent
    {
        if (!isset($this->definitions[$name][$version])) {
            throw new LogicException('The plugin event and version must be declared before publishing.');
        }
        if (trim($id) === '' || strlen($id) > 200 || preg_match('/[\x00-\x1f\x7f]/', $id)) {
            throw new InvalidArgumentException('Invalid plugin event identifier.');
        }
        $this->validateData($data);
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 65536) {
            throw new InvalidArgumentException('Plugin event data exceeds 64 KiB.');
        }
        $event = new PluginEvent($id, $name, $version, $data, now()->toIso8601String());
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(fn () => $this->dispatch($event));
        } else {
            $this->dispatch($event);
        }

        return $event;
    }

    public function failures(): array
    {
        return $this->failures;
    }

    private function dispatch(PluginEvent $event): void
    {
        $listeners = [
            ...array_values($this->listeners[$event->name][$event->version] ?? []),
            ...array_values($this->listeners['*'][$event->version] ?? []),
        ];
        $ids = [
            ...array_keys($this->listeners[$event->name][$event->version] ?? []),
            ...array_keys($this->listeners['*'][$event->version] ?? []),
        ];
        foreach ($listeners as $index => $listener) {
            try {
                $listener($event);
            } catch (Throwable $exception) {
                $failure = [
                    'event' => $event->name,
                    'version' => $event->version,
                    'event_id' => $event->id,
                    'listener' => $ids[$index],
                    'exception' => $exception::class,
                ];
                $this->failures[] = $failure;
                $this->failures = array_slice($this->failures, -100);
                try {
                    Log::error('Plugin event listener failed.', $failure);
                } catch (Throwable) {
                }
            }
        }
    }

    private function validateName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,59}(?:\.[a-z][a-z0-9_-]{0,59}){1,4}$/D', $name)) {
            throw new InvalidArgumentException('Plugin events must use an extension namespace.');
        }
    }

    private function validateData(array $data, int $depth = 0): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('Plugin event data is nested too deeply.');
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->validateData($value, $depth + 1);
            } elseif (!is_null($value) && !is_scalar($value)) {
                throw new InvalidArgumentException('Plugin events only accept scalar values and arrays.');
            }
        }
    }
}
