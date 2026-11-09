<?php

namespace App\Kraft;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Closure;

class SocialLinks
{
    public const array TRANSLATION_FIELDS = ['title' => ['max' => 80]];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function all(): array
    {
        $links = $this->settings->get('social.links', []);

        return is_array($links) ? $links : [];
    }

    public function visible(): array
    {
        $links = array_filter(
            $this->all(),
            fn ($link) => ($link['enabled'] ?? false) && NavigationTargets::validUrl($link['url'] ?? null),
        );

        return array_values(
            array_map(function (array $link) {
                /** @var TextTranslations $translations */
                $translations = app(TextTranslations::class);
                $link['title'] = $translations->resolve($link['title'], $link['translations'] ?? [], 'title');

                return $link;
            }, $links),
        );
    }

    public function store(?string $id, array $data): void
    {
        $savedId = $id ?? (string) Str::uuid();
        $this->mutate(function (array $links) use ($id, $data, $savedId): array {
            $index = $id === null ? null : array_search($id, array_column($links, 'id'), true);
            if ($id !== null && $index === false) {
                throw new NotFoundHttpException();
            }
            if ($id === null && count($links) >= config('social.max_links', 30)) {
                throw ValidationException::withMessages([
                    'title' => __('admin.social.limit', ['max' => config('social.max_links', 30)]),
                ]);
            }
            $data['translations'] = app(TextTranslations::class)->normalize(
                $data['translations'] ?? [],
                $id === null ? [] : $links[$index]['translations'] ?? [],
                self::TRANSLATION_FIELDS,
            );
            $link = $data + ['id' => $savedId];
            if ($id === null) {
                $links[] = $link;
            } else {
                $links[$index] = $link;
            }

            return $links;
        });
        Audit::record($id === null ? 'social.created' : 'social.updated', ['id' => $savedId, 'type' => $data['type']]);
    }

    public function destroy(string $id): void
    {
        $this->mutate(function (array $links) use ($id): array {
            if (!in_array($id, array_column($links, 'id'), true)) {
                throw new NotFoundHttpException();
            }

            return array_values(array_filter($links, fn ($link) => $link['id'] !== $id));
        });
        Audit::record('social.deleted', ['id' => $id]);
    }

    public function move(string $id, string $direction): void
    {
        $this->mutate(function (array $links) use ($id, $direction): array {
            $index = array_search($id, array_column($links, 'id'), true);
            if ($index === false) {
                throw new NotFoundHttpException();
            }
            $target = $index + ($direction === 'up' ? -1 : 1);
            if (isset($links[$target])) {
                [$links[$index], $links[$target]] = [$links[$target], $links[$index]];
            }

            return $links;
        });
        Audit::record('social.reordered', ['id' => $id, 'direction' => $direction]);
    }

    private function mutate(Closure $change): void
    {
        DB::transaction(function () use ($change) {
            Setting::query()->firstOrCreate(['key' => 'social.links'], ['value' => $this->all()]);
            $stored = Setting::query()->where('key', 'social.links')->lockForUpdate()->value('value');
            $links = $stored;
            $this->settings->set('social.links', array_values($change($links)));
        }, 3);
    }
}
