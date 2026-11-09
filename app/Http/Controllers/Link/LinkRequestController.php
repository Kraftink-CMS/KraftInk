<?php

namespace App\Http\Controllers\Link;

use App\Kraft\Link\LinkOperation;
use App\Kraft\Link\LinkRequestRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use stdClass;

class LinkRequestController
{
    private const int MAX_BYTES = 65_536;

    public function __invoke(Request $request, LinkRequestRegistry $registry): JsonResponse
    {
        $raw = $request->getContent();
        $result = LinkOperation::run(function () use ($request, $registry, $raw): array {
            abort_if(strlen($raw) > self::MAX_BYTES, 413);
            abort_unless($request->isJson(), 422);
            $body = json_decode($raw, depth: 64);
            abort_unless($body instanceof stdClass && ($body->payload ?? null) instanceof stdClass, 422);
            $data = Validator::make(json_decode($raw, true, 64), [
                'protocol' => ['required', 'integer', 'in:1'],
                'operation' => ['required', 'string', 'max:64'],
                'payload' => ['present', 'array'],
            ])->validate();
            abort_unless(LinkOperation::validExtensionName($data['operation']), 422);

            return $registry->handle($request->attributes->get('link.server'), $data['operation'], $data['payload']);
        });

        return response()->json($result, $result['status'] ?? 200, ['Cache-Control' => 'private, no-store']);
    }
}
