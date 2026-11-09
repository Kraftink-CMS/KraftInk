@use('App\Kraft\SiteDates')
@use('App\Kraft\Wallet\Money')
@extends('admin.layouts.layout')
@section('title', __('site.account.wallet.admin_title'))
@section('actions')
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#wallet-settings">
            <i class="bi bi-gear me-1" aria-hidden="true"></i>{{ __('site.account.wallet.settings') }}
        </button>
        <a class="btn btn-outline-secondary" href="{{ route('admin.wallet.export') }}">
            <i class="bi bi-download me-1" aria-hidden="true"></i>{{ __('site.account.wallet.export') }}
        </a>
    </div>
@endsection
@section('content')
    <div id="admin-wallet-members" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$members" class="mb-3" />
                <form class="row g-2" method="get" action="{{ route('admin.wallet.index') }}">
                    <div class="col-md-6 col-lg-5">
                        <label class="visually-hidden" for="wallet_search">{{ __('site.account.wallet.search') }}</label>
                        <input class="form-control" id="wallet_search" name="q" value="{{ request('q') }}"
                            maxlength="160" placeholder="{{ __('site.account.wallet.search_placeholder') }}">
                    </div>
                    <noscript>
                        <div class="col-auto">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-search me-1" aria-hidden="true"></i>{{ __('common.actions.search') }}
                            </button>
                        </div>
                    </noscript>
                    @if (request()->filled('q'))
                        <div class="col-auto">
                            <a class="btn btn-outline-secondary"
                                href="{{ route('admin.wallet.index') }}">{{ __('site.account.wallet.clear_search') }}</a>
                        </div>
                    @endif
                </form>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th data-table-sort="username">{{ __('site.account.wallet.member') }}</th>
                            <th class="text-end">{{ __('site.account.wallet.balance') }}</th>
                            <th class="text-end">{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($members as $member)
                            @php
                                $memberBalanceMinor = $member->walletAccount?->balance_minor ?? 0;
                                $memberBalance = Money::format($memberBalanceMinor) . ' ' . $currency;
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $member->username }} <span
                                            class="text-body-secondary fw-normal">#{{ $member->id }}</span></div>
                                    <div class="small text-body-secondary">{{ $member->email }}</div>
                                </td>
                                <td class="text-end text-nowrap">{{ $memberBalance }}</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal"
                                        data-bs-target="#wallet-adjust" data-wallet-member="{{ $member->id }}"
                                        data-wallet-username="{{ $member->username }}" data-wallet-balance="{{ $memberBalance }}"
                                        data-wallet-balance-minor="{{ $memberBalanceMinor }}"
                                        aria-label="{{ __('site.account.wallet.manage_member', ['member' => $member->username]) }}">
                                        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>{{ __('site.account.wallet.manage') }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-4 text-body-secondary">{{ __('site.account.wallet.no_member') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $members->links() }}</div>
        </section>
    </div>
    <x-forms.modal id="wallet-adjust" :title="__('site.account.wallet.adjust')" :action="route('admin.wallet.adjust')" :submit="__('site.account.wallet.confirm_adjustment')" :open="$errors->walletAdjustment->any() || ((bool) $selectedMember && !$errors->walletSettings->any())"
        error-bag="walletAdjustment"
        data-wallet-form data-request-id="{{ $requestId }}" data-wallet-currency="{{ $currency }}"
        data-wallet-max-balance="{{ Money::MaxBalance }}"
        data-wallet-balance-minor="{{ $selectedMember?->walletAccount?->balance_minor ?? 0 }}">
        <input type="hidden" name="user_id" value="{{ $selectedMember?->id }}">
        <input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
        <div class="d-flex justify-content-between align-items-center gap-3 border rounded p-3 mb-3">
            <div>
                <div class="small text-body-secondary">{{ __('site.account.wallet.member') }}</div>
                <div class="fw-semibold" data-wallet-member-username>{{ $selectedMember?->username }}</div>
            </div>
            <div class="text-end">
                <div class="small text-body-secondary">{{ __('site.account.wallet.balance') }}</div>
                <div class="fw-semibold text-nowrap" data-wallet-member-balance>
                    @if ($selectedMember)
                        {{ Money::format($selectedMember->walletAccount?->balance_minor ?? 0) }}
                        {{ $currency }}
                    @endif
                </div>
            </div>
        </div>
        <p class="small text-body-secondary">{{ __('site.account.wallet.adjust_help') }}</p>
        <fieldset class="mb-3">
            <legend class="form-label fs-6">{{ __('site.account.wallet.direction') }}</legend>
            <div class="d-flex flex-wrap gap-3">
                @foreach (['credit', 'debit', 'set'] as $direction)
                    <div class="form-check">
                        <input class="form-check-input" id="wallet_{{ $direction }}" type="radio" name="direction"
                            value="{{ $direction }}" @checked(old('direction', 'credit') === $direction) required>
                        <label class="form-check-label"
                            for="wallet_{{ $direction }}">{{ __('site.account.wallet.' . $direction) }}</label>
                    </div>
                @endforeach
            </div>
        </fieldset>
        <div class="mb-3">
            <label class="form-label" for="wallet_amount" data-wallet-amount-label
                data-amount-label="{{ __('site.account.wallet.amount') }} ({{ $currency }})"
                data-target-label="{{ __('site.account.wallet.target_balance') }} ({{ $currency }})">
                {{ __(old('direction') === 'set' ? 'site.account.wallet.target_balance' : 'site.account.wallet.amount') }} ({{ $currency }})
            </label>
            <input class="form-control" id="wallet_amount" name="amount" value="{{ old('amount') }}" inputmode="decimal"
                maxlength="30" aria-describedby="wallet_amount_help wallet_preview_feedback" required>
            <div class="form-text" id="wallet_amount_help" data-wallet-amount-help
                data-amount-help="{{ __('site.account.wallet.format_help') }}" data-target-help="{{ __('site.account.wallet.set_help') }}">
                {{ __(old('direction') === 'set' ? 'site.account.wallet.set_help' : 'site.account.wallet.format_help') }}
            </div>
        </div>
        <div class="bg-body-tertiary rounded p-3 mb-3" data-wallet-preview aria-live="polite" aria-atomic="true"
            data-invalid-amount="{{ __('site.account.wallet.invalid_amount') }}"
            data-insufficient-balance="{{ __('site.account.wallet.preview_insufficient') }}"
            data-balance-limit="{{ __('site.account.wallet.balance_limit') }}">
            <div class="small text-body-secondary">{{ __('site.account.wallet.after') }}</div>
            <output class="d-block fs-4 fw-semibold text-body-secondary" for="wallet_amount wallet_credit wallet_debit wallet_set" data-wallet-final-balance>—</output>
            <div class="small text-danger mt-1" id="wallet_preview_feedback" data-wallet-preview-feedback hidden></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="wallet_reason">{{ __('site.account.wallet.reason') }}</label>
            <input class="form-control" id="wallet_reason" name="reason" value="{{ old('reason') }}" maxlength="160"
                required>
        </div>
        @include('partials.forms.confirm-action', ['confirmationId' => 'wallet_adjust'])
    </x-forms.modal>

    <x-forms.modal id="wallet-settings" :title="__('site.account.wallet.settings')" :action="route('admin.wallet.settings.update')" :open="$errors->walletSettings->any()" size="lg" error-bag="walletSettings">
        <div class="mb-3">
            <label class="form-label" for="wallet_currency">{{ __('site.account.wallet.currency') }}</label>
            <select class="form-select" id="wallet_currency" name="currency" data-searchable-select
                aria-describedby="wallet_currency_help" required>
                @if (!isset($currencies[$currency]))
                    <option value="{{ $currency }}" selected>{{ $currency }}</option>
                @endif
                @foreach ($currencies as $supportedCurrency)
                    <option value="{{ $supportedCurrency->code }}" @selected(old('currency', $currency) === $supportedCurrency->code)>
                        {{ $supportedCurrency->code }}{{ $supportedCurrency->label() !== $supportedCurrency->code ? ' · ' . $supportedCurrency->label() : '' }}
                    </option>
                @endforeach
            </select>
            <div class="form-text" id="wallet_currency_help">{{ __('site.account.wallet.currency_help') }}</div>
        </div>
        <input type="hidden" name="transfers" value="0">
        <div class="form-check">
            <input class="form-check-input" id="wallet_transfers" type="checkbox" name="transfers" value="1"
                @checked(old('transfers', $transfers))>
            <label class="form-check-label" for="wallet_transfers">{{ __('site.account.wallet.allow_transfers') }}</label>
        </div>
    </x-forms.modal>

    <div id="admin-wallet-history" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('site.account.wallet.history') }}</h2>
            </div>
            <x-tables.controls :paginator="$entries" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="text-nowrap">
                        <tr>
                            <th data-table-sort="created_at">{{ __('site.account.wallet.date') }}</th>
                            <th>{{ __('site.account.wallet.member') }}</th>
                            <th>{{ __('site.account.wallet.direction') }}</th>
                            <th>{{ __('site.account.wallet.reason') }}</th>
                            <th class="text-end" data-table-sort="amount_minor">{{ __('site.account.wallet.amount') }}</th>
                            <th class="text-end" data-table-sort="balance_after">{{ __('site.account.wallet.after') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            <tr>
                                <td class="text-nowrap">
                                    {{ app(SiteDates::class)->format($entry->created_at, 'd M Y H:i T') }}</td>
                                <td>{{ $entry->user?->username }}</td>
                                <td>{{ __('site.account.wallet.' . $entry->transaction->kind . '_kind') }}</td>
                                <td>{{ $entry->transaction->reasonLabel() }}</td>
                                <td class="text-end text-nowrap">
                                    {{ $entry->amount_minor > 0 ? '+' : '' }}{{ Money::format($entry->amount_minor) }}
                                    {{ $entry->transaction->currency }}
                                </td>
                                <td class="text-end text-nowrap">
                                    {{ Money::format($entry->balance_after) }}
                                    {{ $entry->transaction->currency }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-4 text-body-secondary">{{ __('site.account.wallet.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $entries->links() }}
            </div>
        </section>
    </div>
@endsection
