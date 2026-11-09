<?php

namespace App\Http\Controllers\Link;

use App\Kraft\Link\LinkOperation;
use App\Kraft\Link\LinkPollRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LinkPollController
{
    private const int MAX_BYTES = 1_048_576;

    public function __invoke(Request $request, LinkPollRegistry $registry): JsonResponse
    {
        abort_if(strlen($request->getContent()) > self::MAX_BYTES, 413);
        $data = $request->validate([
            'protocol' => ['required', 'integer', 'in:1'],
            'operations' => ['required', 'array', 'min:1', 'max:'.LinkPollRegistry::MAX_OPERATIONS],
        ]);
        foreach (array_keys($data['operations']) as $operation) {
            if (!is_string($operation) || !LinkOperation::validName($operation)) {
                throw ValidationException::withMessages(['operations' => __('validation.array', ['attribute' => 'operations'])]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'protocol' => 1,
                'operations' => $registry->poll(
                    $request->attributes->get('link.server'),
                    $data['operations'],
                    $request->decodedPath(),
                ),
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
