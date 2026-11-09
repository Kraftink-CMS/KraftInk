<div class="modal" tabindex="-1" aria-labelledby="auth-dialog-title" data-auth-dialog data-auth-shell
    data-csrf-token="{{ csrf_token() }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-4" id="auth-dialog-title" data-auth-step-title tabindex="-1">{{ $authTitle }}</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                    aria-label="{{ __('common.actions.cancel') }}"></button>
            </div>
            <div class="modal-body">
                <div data-ajax-messages hidden></div>
                @include($authFragment, [
                    'inAuthModal' => true,
                    'authInputPrefix' => 'auth-modal-',
                ])
            </div>
        </div>
    </div>
</div>
