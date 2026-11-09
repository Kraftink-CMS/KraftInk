<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Support\Facades\Validator;
use App\Models\Audit\AuditEntry;
use App\Http\Controllers\Controller;
use App\Kraft\Extensions\ExtensionManager;
use App\Models\Content\ContentEntry;
use App\Models\Servers\Server;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Kraft\DashboardStatistics;
use App\Kraft\Games\GameRegistry;
use JsonException;

class AdminController extends Controller
{
    /**
     * @throws JsonException
     */
    public function dashboard(Request $request): View
    {
        $validator = Validator::make($request->all(), ['period' => ['sometimes', 'integer', Rule::in([7, 30, 90])]]);
        $period = (int) ($validator->validate()['period'] ?? 30);

        return view('admin.dashboard.index', [
            'statistics' => app(DashboardStatistics::class)->for($request->user(), $period),
            'stats' => [
                'users' => User::members()->whereNull('anonymized_at')->count(),
                'news' => ContentEntry::query()->where('kind', 'news')->count(),
                'servers' => Server::query()->where('game', app(GameRegistry::class)->activeId())->count(),
                'extensions' => collect(app(ExtensionManager::class)->all())
                    ->filter(
                        fn ($extension) => $extension['valid'] &&
                            $extension['enabled'] &&
                            in_array($extension['type'], ['plugin', 'theme'], true),
                    )
                    ->count(),
            ],
            'logs' => AuditEntry::query()->latest('id')->limit(12)->get(),
        ]);
    }
}
