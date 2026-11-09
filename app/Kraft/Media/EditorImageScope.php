<?php

namespace App\Kraft\Media;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

readonly class EditorImageScope
{
    public string $images;

    public string $usages;

    public string $resources;

    /**
     * @param  class-string<Model>  $imageModel
     * @param  class-string<Model>  $usageModel
     * @param  class-string<Model>  $resourceModel
     */
    public function __construct(
        public string $id,
        public string $imageModel,
        public string $usageModel,
        public string $resourceModel,
        public string $route,
        public string $permission,
        public Closure $visible,
        public ?Closure $history = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $id)) {
            throw new InvalidArgumentException('Invalid editor image scope.');
        }
        foreach ([$imageModel, $usageModel, $resourceModel] as $model) {
            if (!is_subclass_of($model, Model::class)) {
                throw new InvalidArgumentException('Invalid editor image model.');
            }
        }
        $this->images = new $imageModel()->getTable();
        $this->usages = new $usageModel()->getTable();
        $this->resources = new $resourceModel()->getTable();
        if (
            !preg_match('/^[a-z][a-z0-9_.-]{0,199}$/D', $route) ||
            !preg_match('/^[a-z][a-z0-9_.-]{0,199}$/D', $permission)
        ) {
            throw new InvalidArgumentException('Invalid editor image access configuration.');
        }
    }

    /** @return Builder<Model> */
    public function images(): Builder
    {
        return $this->imageModel::query();
    }

    /** @return Builder<Model> */
    public function usages(): Builder
    {
        return $this->usageModel::query();
    }

    /** @return Builder<Model> */
    public function resources(): Builder
    {
        return $this->resourceModel::query();
    }
}
