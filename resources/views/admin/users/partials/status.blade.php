@if ($member->anonymized_at)
    <span class="badge text-bg-secondary">{{ __('admin.members.closed') }}</span>
@elseif($member->isBanned())
    <span class="badge text-bg-danger">{{ __('admin.members.banned') }}</span>
@elseif($member->import_locked)
    <span class="badge text-bg-warning">{{ __('system.imports.needs_review') }}</span>
@elseif(!$member->email_verified_at)
    <span class="badge text-bg-secondary">{{ __('admin.members.unverified') }}</span>
@else
    <span class="badge text-bg-success">{{ __('admin.members.active') }}</span>
@endif
