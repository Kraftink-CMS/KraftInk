<?php

namespace App\Http\Controllers\Admin\Content;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Content\ContentFiles;
use App\Kraft\Content\ContentDraftImages;
use App\Kraft\Content\ContentHistory;
use App\Kraft\Content\ContentImages;
use App\Kraft\Media\Images;
use App\Kraft\Content\ContentPublishing;
use App\Kraft\Redirects;
use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentEntry;
use App\Models\Content\ContentImage;
use App\Models\Content\ContentRevision;
use App\Models\Media\MediaAsset;
use App\Models\Accounts\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Kraft\SiteDates;

abstract class AdminContentEntryController extends Controller
{
    protected string $kind;

    protected string $section;

    public function index(Request $request): View
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(['draft', 'scheduled', 'published'])],
        ]);
        $query = ContentEntry::query()->where('kind', $this->kind)->with('author:id,username')->withExists('revisions');
        if (($data['status'] ?? '') === 'draft') {
            $query->whereNull('published_at');
        } elseif (($data['status'] ?? '') === 'scheduled') {
            $query->where('published_at', '>', now());
        } elseif (($data['status'] ?? '') === 'published') {
            $query->whereNotNull('published_at')->where('published_at', '<=', now());
        }
        if (!empty($data['q'])) {
            TableQuery::whereContains($query, ['title'], $data['q']);
        }

        return $this->contentView('index', [
            'entries' => TableQuery::paginate($query->latest('id'), ['title', 'locale', 'visibility', 'published_at']),
        ]);
    }

    public function edit(Request $request, ?ContentEntry $entry = null): View
    {
        $this->checkKind($entry);
        $coverId = old('cover_media_id', $entry?->cover_media_id);
        $privateId = old('cover_image_id', $entry?->cover_image_id);
        $privateCover = is_string($privateId)
            ? ContentImage::query()
                ->whereKey($privateId)
                ->where(function (Builder $images) use ($request, $entry) {
                    $images->where('uploader_id', $request->user()->id);
                    if ($entry) {
                        $images->orWhereHas('entries', fn (Builder $entries) => $entries->whereKey($entry->id));
                    }
                })
                ->first()
            : null;

        return $this->contentView('edit', [
            'entry' => $entry?->load(['roles', 'attachments', 'cover', 'privateCover'])->loadExists('revisions'),
            'roles' => Role::query()->orderByDesc('position')->get(),
            'selectedCover' => is_numeric($coverId) ? MediaAsset::query()->find((int) $coverId) : null,
            'selectedPrivateCover' => $privateCover,
        ]);
    }

    public function store(Request $request, ContentHistory $history, ?ContentEntry $entry = null): RedirectResponse
    {
        $this->checkKind($entry);
        app(SiteDates::class)->normalize($request, ['published_at']);
        $kind = $this->kind;
        $imageTypes = 'mimetypes:'.implode(',', app(Images::class)->supportedMimeTypes());
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(config('kraft.locales')))],
            'slug' => [
                'required',
                'string',
                $kind === 'page'
                    ? 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/'
                    : 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'max:160',
                Rule::unique('content_entries')
                    ->where(fn ($query) => $query->where('kind', $kind)->where('locale', $request->input('locale')))
                    ->ignore($entry?->id),
            ],
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:1000000',
            'summary' => 'nullable|string|max:1000',
            'published_at' => 'nullable|date',
            'cover_media_id' => 'nullable|integer|exists:media_assets,id',
            'cover_image_id' => 'nullable|uuid|exists:content_images,id',
            'cover_visibility' => ['sometimes', 'required', Rule::in(['public', 'private'])],
            'content_images' => 'sometimes|array|max:100',
            'content_images.*' => ['required', 'file', $imageTypes, 'max:'.config('community.media_max_kilobytes', 8192)],
            'cover_local_image' => 'nullable|uuid',
            'cover_upload' => ['nullable', 'file', $imageTypes, 'max:8192'],
            'cover_private_upload' => ['nullable', 'file', $imageTypes, 'max:8192'],
            'is_pinned' => 'sometimes|boolean',
            'comments_enabled' => 'sometimes|boolean',
            'reactions_enabled' => 'sometimes|boolean',
            'comments_moderated' => 'sometimes|boolean',
            'visibility' => ['sometimes', 'required', Rule::in(['public', 'auth', 'restricted'])],
            'role_ids' => 'sometimes|array|max:100',
            'role_ids.*' => 'integer|exists:roles,id',
            'required_permission' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]+$/D'],
        ]);
        $data['kind'] = $kind;
        $roles = array_values(array_unique($data['role_ids'] ?? []));
        $data['visibility'] ??= 'public';
        $data['roles_restricted'] = $data['visibility'] === 'restricted' && count($roles) > 0;
        if ($data['visibility'] === 'restricted' && !$roles && empty($data['required_permission'])) {
            throw ValidationException::withMessages(['role_ids' => __('site.content.restricted_requires_grant')]);
        }
        $coverVisibility =
            $data['cover_visibility'] ??
            (!empty($data['cover_local_image']) || !empty($data['cover_image_id']) || $entry?->cover_image_id
                ? 'private'
                : 'public');
        if ($request->hasFile('cover_private_upload')) {
            $coverVisibility = 'private';
        }
        if ($coverVisibility === 'private') {
            $data['cover_media_id'] = null;
            $data['cover_image_id'] = array_key_exists('cover_image_id', $data)
                ? $data['cover_image_id']
                : $entry?->cover_image_id;
        } else {
            $data['cover_image_id'] = null;
        }
        $draftImages = new ContentDraftImages();
        $draftImages->prepare($data, $coverVisibility);
        unset($data['role_ids']);
        foreach (['is_pinned', 'comments_enabled', 'reactions_enabled', 'comments_moderated'] as $flag) {
            $data[$flag] = $kind === 'news' && $request->boolean($flag);
        }
        $saved = $draftImages->transaction(function () use ($entry, $request, $data, $roles, $history, $draftImages) {
            $record = $entry
                ? ContentEntry::query()->where('kind', $this->kind)->lockForUpdate()->findOrFail($entry->id)
                : new ContentEntry();
            $oldPath = $record->exists ? parse_url($record->url(), PHP_URL_PATH) : null;
            if ($record->exists) {
                $history->record($record, $request->user(), 'before_edit');
            } else {
                $record->author_id = $request->user()->id;
            }
            $data = $draftImages->resolve($data, $request->user());
            $record->fill($data)->save();
            $record->roles()->sync($data['visibility'] === 'restricted' ? $roles : []);
            app(ContentImages::class)->attach($record, $request->user());
            if ($oldPath && $oldPath !== parse_url($record->url(), PHP_URL_PATH)) {
                app(Redirects::class)->preserve($oldPath, parse_url($record->url(), PHP_URL_PATH));
            }
            $history->record($record, $request->user(), 'saved');
            Audit::record('content.saved', ['id' => $record->id]);

            return $record;
        });
        app(ContentPublishing::class)->dispatchDue($saved->id);

        return redirect()
            ->route('admin.'.$this->section.'.index')
            ->with('status', __('admin.'.$this->section.'.saved'));
    }

    public function destroy(Request $request, ContentEntry $entry, ContentHistory $history): RedirectResponse
    {
        $this->checkKind($entry);
        DB::transaction(function () use ($entry, $request, $history) {
            $entry = ContentEntry::query()->where('kind', $this->kind)->lockForUpdate()->findOrFail($entry->id);
            $history->record($entry, $request->user(), 'deleted');
            $entry->delete();
        });
        Audit::record('content.deleted', ['id' => $entry->id]);

        return redirect()
            ->route('admin.'.$this->section.'.index')
            ->with('status', __('admin.'.$this->section.'.moved_to_trash'));
    }

    public function history(ContentEntry $entry): View
    {
        $this->checkKind($entry);

        return $this->contentView('history', [
            'entry' => $entry,
            'revisions' => $entry->revisions()->latest('id')->paginate(20),
        ]);
    }

    public function restore(
        Request $request,
        ContentEntry $entry,
        int $revision,
        ContentHistory $history,
    ): RedirectResponse {
        $this->checkKind($entry);
        $version = ContentRevision::query()->where('content_entry_id', $entry->id)->findOrFail($revision);
        if (($version->snapshot['kind'] ?? $this->kind) !== $this->kind) {
            abort(404);
        }
        $history->restore($entry, $version, $request->user());
        app(ContentPublishing::class)->dispatchDue($entry->id);
        Audit::record('content.restored', ['entry' => $entry->id, 'revision' => $version->id]);

        return redirect()
            ->route('admin.'.$this->section.'.edit', $entry)
            ->with('status', __('admin.'.$this->section.'.restored'));
    }

    public function attachment(Request $request, ContentEntry $entry, ContentFiles $files): RedirectResponse
    {
        $this->checkKind($entry);
        $request->validate([
            'file' => 'required|file|mimes:pdf,txt,jpg,jpeg,png,gif,webp,zip|max:20480',
        ]);

        $files->store($entry, $request->file('file'), $request->user());
        Audit::record('content.attachment_uploaded', ['entry' => $entry->id]);

        return back()->with('status', __('site.content.attachment_saved'));
    }

    public function deleteAttachment(Request $request, ContentEntry $entry, int $attachment): RedirectResponse
    {
        $this->checkKind($entry);
        DB::transaction(function () use ($entry, $attachment, $request) {
            $record = ContentEntry::query()->where('kind', $this->kind)->lockForUpdate()->findOrFail($entry->id);
            $file = ContentAttachment::query()
                ->where('content_entry_id', $record->id)
                ->lockForUpdate()
                ->findOrFail($attachment);
            app(ContentHistory::class)->record($record, $request->user(), 'before_attachment');
            $file->delete();
            $record->touch();
        });
        Audit::record('content.attachment_deleted', ['id' => $attachment]);

        return back();
    }

    public function download(ContentEntry $entry, int $attachment): StreamedResponse
    {
        $this->checkKind($entry);
        /** @var ContentAttachment $file */
        $file = $entry->attachments()->findOrFail($attachment);
        if (!Storage::disk('local')->exists($file->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function checkKind(?ContentEntry $entry): void
    {
        if ($entry && $entry->kind !== $this->kind) {
            abort(404);
        }
    }

    private function contentView(string $name, array $data): View
    {
        return view(
            'admin.content.'.$name,
            $data + [
                'contentKind' => $this->kind,
                'contentSection' => $this->section,
                'contentRoutes' => 'admin.'.$this->section,
            ],
        );
    }
}
