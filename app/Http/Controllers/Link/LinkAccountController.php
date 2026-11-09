<?php

namespace App\Http\Controllers\Link;

use App\Http\Controllers\Controller;
use App\Kraft\Link\Accounts\AccountOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class LinkAccountController extends Controller
{
    /**
     * @throws JsonException
     */
    public function policy(Request $request, AccountOperations $accounts): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $accounts->policy((string) $request->bearerToken())]);
    }

    /**
     * @throws JsonException
     */
    public function profile(Request $request, AccountOperations $accounts, string $uuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $accounts->profile((string) $request->bearerToken(), $uuid),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function balance(Request $request, AccountOperations $accounts, string $uuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $accounts->balance((string) $request->bearerToken(), $uuid),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function register(Request $request, AccountOperations $accounts): JsonResponse
    {
        return response()->json(
            ['success' => true, 'data' => $accounts->register((string) $request->bearerToken(), $request)],
            201,
        );
    }

    /**
     * @throws JsonException
     */
    public function email(Request $request, AccountOperations $accounts, string $uuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $accounts->changeEmail((string) $request->bearerToken(), $uuid, $request->all()),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function password(Request $request, AccountOperations $accounts, string $uuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $accounts->changePassword((string) $request->bearerToken(), $uuid, $request->all()),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function adjust(Request $request, AccountOperations $accounts, string $uuid, string $kind): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $accounts->adjust((string) $request->bearerToken(), $uuid, $kind, $request->all()),
        ]);
    }
}
