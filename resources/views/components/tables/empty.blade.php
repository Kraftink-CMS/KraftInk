@props(['colspan', 'message', 'description' => null, 'action' => null, 'modal' => null, 'href' => null])
<tr>
    <td colspan="{{ $colspan }}" class="text-center py-5">
        <p class="text-body-secondary mb-0">{{ $message }}</p>
        @if ($description)
            <p class="small text-body-secondary mt-2 mb-0">{{ $description }}</p>
        @endif
        @if ($action && $modal)
            <button class="btn btn-outline-primary btn-sm mt-3" type="button" data-bs-toggle="modal"
                data-bs-target="#{{ $modal }}">{{ $action }}</button>
        @elseif($action && $href)
            <a class="btn btn-outline-primary btn-sm mt-3" href="{{ $href }}">{{ $action }}</a>
        @endif
    </td>
</tr>
