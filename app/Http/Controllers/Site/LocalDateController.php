<?php

namespace App\Http\Controllers\Site;

use App\Kraft\SiteDates;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Http\JsonResponse;
use DateInvalidTimeZoneException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LocalDateController
{
    /**
     * @throws DateInvalidTimeZoneException
     */
    public function __invoke(Request $request, SiteDates $dates): JsonResponse
    {
        $data = $request->validate([
            'value' => 'required|string|max:30',
            'timezone' => 'required|string|max:80',
        ]);
        if ($data['timezone'] !== $dates->timezone()) {
            throw new HttpException(409, __('common.dates.changed_timezone'));
        }
        try {
            $choices = $dates->candidates($data['value']);
        } catch (InvalidArgumentException) {
            return response()
                ->json(['success' => false, 'data' => ['message' => __('common.dates.invalid')]], 422)
                ->header('Cache-Control', 'no-store');
        }

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'choices' => $choices,
                    'message' => $choices
                        ? (count($choices) > 1
                            ? __('common.dates.ambiguous', ['zone' => $dates->timezone()])
                            : '')
                        : __('common.dates.nonexistent', ['zone' => $dates->timezone()]),
                ],
            ])
            ->header('Cache-Control', 'no-store');
    }
}
