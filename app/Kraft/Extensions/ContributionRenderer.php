<?php

namespace App\Kraft\Extensions;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use LengthException;
use Throwable;
use UnexpectedValueException;

class ContributionRenderer
{
    public function render(Closure $resolve): string
    {
        $restoreViewState = (function (): Closure {
            $state = get_object_vars($this);

            return function () use ($state): void {
                foreach ($state as $property => $value) {
                    $this->{$property} = $value;
                }
            };
        })->call(app('view'));
        $bufferLevel = ob_get_level();
        try {
            $value = $resolve();
            $html = match (true) {
                $value instanceof Htmlable => $value->toHtml(),
                $value instanceof Renderable => $value->render(),
                is_string($value) => $value,
                default => throw new UnexpectedValueException('An extension contribution must render HTML.'),
            };
            if (strlen($html) > 1048576) {
                throw new LengthException('The extension contribution is too large.');
            }

            return $html;
        } catch (Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                if (!ob_end_clean()) {
                    break;
                }
            }
            $restoreViewState();

            throw $exception;
        }
    }
}
