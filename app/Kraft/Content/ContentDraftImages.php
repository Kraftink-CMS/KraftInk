<?php

namespace App\Kraft\Content;

use App\Kraft\Html;
use App\Kraft\Media\MediaLibrary;
use App\Models\Accounts\User;
use Closure;
use DOMDocument;
use DOMElement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;
use App\Kraft\Media\FileDeletionQueue;

class ContentDraftImages
{
    private array $files = [];

    private array $inline = [];

    private ?string $cover = null;

    private ?DOMElement $body = null;

    private array $written = [];

    private string $visibility = 'private';

    private ?UploadedFile $publicFile = null;

    private ?Closure $publicWriter = null;

    public function prepare(array $data, string $visibility): void
    {
        if (!in_array($visibility, ['private', 'public'], true)) {
            $this->invalid('cover_visibility');
        }
        $this->visibility = $visibility;
        $files = $data['content_images'] ?? [];
        if (!is_array($files) || count($files) > 100) {
            $this->invalid('content_images');
        }
        $bytes = 0;
        foreach ($files as $key => $file) {
            $token = $this->token($key, 'content_images');
            if (isset($this->files[$token])) {
                $this->invalid('content_images');
            }
            $this->validateFile($file, 'content_images.'.$token);
            $bytes += $file->getSize();
            $this->files[$token] = $file;
        }
        if ($bytes > 134217728) {
            $this->invalid('content_images');
        }
        foreach (['cover_upload', 'cover_private_upload'] as $field) {
            if (isset($data[$field])) {
                $this->validateFile($data[$field], $field);
            }
        }
        if (!empty($data['cover_local_image'])) {
            $this->cover = $this->token($data['cover_local_image'], 'cover_local_image');
            if (!isset($this->files[$this->cover])) {
                $this->invalid('cover_local_image');
            }
        }
        $this->publicFile =
            $visibility === 'public'
                ? ($this->cover
                    ? $this->files[$this->cover]
                    : $data['cover_upload'] ?? null)
                : null;
        $body = $this->parse($data['body']);
        $prefix = '/__kraft_content_draft_'.Str::random(32).'/';
        foreach ($body->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('data-content-image')) {
                if ($element->tagName !== 'img') {
                    $this->invalid('body');
                }
                $token = $this->token($element->getAttribute('data-content-image'), 'body');
                if (!isset($this->files[$token])) {
                    $this->invalid('body');
                }
                $element->setAttribute('src', $prefix.$token);
                $element->removeAttribute('data-content-image');
                $element->removeAttribute('data-mce-src');
                $element->removeAttribute('srcset');
            } elseif ($element->tagName === 'img') {
                foreach (['src', 'data-mce-src', 'srcset'] as $attribute) {
                    if (preg_match('/(?:^|[\s,])(?:blob|data):/i', trim($element->getAttribute($attribute)))) {
                        $this->invalid('body');
                    }
                }
            }
        }
        $this->body = $this->parse(Html::clean($this->serialize($body)));
        foreach ($this->body->getElementsByTagName('img') as $image) {
            $source = $image->getAttribute('src');
            if (str_starts_with($source, $prefix)) {
                $token = substr($source, strlen($prefix));
                $this->inline[$token][] = $image;
            }
        }
    }

    public function resolve(array $data, User $actor): array
    {
        $trackWrite = function (string $path): void {
            $this->written[$path] = true;
        };
        if ($this->publicFile) {
            if (!$this->publicWriter) {
                throw new LogicException('Public images require the transaction upload lock.');
            }
            $data['cover_media_id'] = ($this->publicWriter)($actor->id, $data['title'], $trackWrite)->id;
            $data['cover_image_id'] = null;
        }
        $ids = array_keys($this->inline);
        if ($this->visibility === 'private' && $this->cover) {
            $ids[] = $this->cover;
        }
        $images = [];
        foreach (array_unique($ids) as $token) {
            $images[$token] = app(ContentImages::class)->store($actor, $this->files[$token], $trackWrite);
        }
        if ($this->visibility === 'private') {
            if ($this->cover) {
                $data['cover_image_id'] = $images[$this->cover]->id;
            } elseif (isset($data['cover_private_upload'])) {
                $data['cover_image_id'] = app(ContentImages::class)->store(
                    $actor,
                    $data['cover_private_upload'],
                    $trackWrite,
                )->id;
            }
        }
        foreach ($this->inline as $token => $elements) {
            $address = route('site.content.images.show', $images[$token]);
            foreach ($elements as $image) {
                $image->setAttribute('src', $address);
            }
        }
        $data['body'] = Html::clean($this->serialize($this->body));
        unset(
            $data['content_images'],
            $data['cover_local_image'],
            $data['cover_upload'],
            $data['cover_private_upload'],
            $data['cover_visibility'],
        );

        return $data;
    }

    /**
     * @throws Throwable
     */
    public function transaction(Closure $operation): mixed
    {
        if (!$this->publicFile) {
            return $this->withinTransaction($operation);
        }

        return app(MediaLibrary::class)->withUploadLock(
            $this->publicFile,
            function (Closure $write) use ($operation) {
                $this->publicWriter = $write;
                try {
                    return $this->withinTransaction($operation);
                } finally {
                    $this->publicWriter = null;
                }
            },
        );
    }

    private function withinTransaction(Closure $operation): mixed
    {
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $result = $operation();
            DB::commit();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > $level) {
                $privatePaths = [];
                try {
                    foreach (array_keys($this->written) as $path) {
                        if (str_starts_with($path, 'content-images/')) {
                            $privatePaths[] = $path;
                        } else {
                            Storage::disk('local')->delete($path);
                        }
                    }
                } finally {
                    DB::rollBack($level);
                }
                foreach ($privatePaths as $path) {
                    app(FileDeletionQueue::class)->schedule($path, 'content-images', [
                        'content_images' => 'path',
                    ]);
                }
            }
            throw $exception;
        } finally {
            $this->written = [];
        }

        return $result;
    }

    private function validateFile(mixed $file, string $field): void
    {
        if (!($file instanceof UploadedFile)) {
            $this->invalid($field);
        }
        app(ContentImages::class)->validateUpload($file, field: $field);
    }

    private function token(mixed $value, string $field): string
    {
        if (!is_string($value) || !Str::isUuid($value)) {
            $this->invalid($field);
        }

        return strtolower($value);
    }

    private function parse(string $html): DOMElement
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="utf-8" ?><body>'.$html.'</body>',
                LIBXML_NONET | LIBXML_HTML_NODEFDTD,
            );
            $body = $document->getElementsByTagName('body')->item(0);
            if (!($body instanceof DOMElement)) {
                $this->invalid('body');
            }

            return $body;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function serialize(DOMElement $body): string
    {
        $html = '';
        foreach ($body->childNodes as $node) {
            $html .= $body->ownerDocument->saveHTML($node);
        }

        return $html;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => __('site.community.invalid_image')]);
    }
}
