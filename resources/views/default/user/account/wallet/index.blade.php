@use('App\Kraft\SiteDates')
@use('App\Kraft\Wallet\Money')
@extends('default.layouts.layout')
@section('title', __('site.account.wallet.title'))
@section('content')
    <header class="my-4">
        <a href="{{ route('account.index') }}">← {{ __('site.account.wallet.back') }}</a>
        <h1 class="h3 mt-3">{{ __('site.account.wallet.title') }}</h1>
        <p class="text-body-secondary mb-1">{{ __('site.account.wallet.balance') }}</p>
        <p class="h2">{{ Money::format($balance) }} {{ $currency }}</p>
    </header>
    <div class="row g-4 pb-4">
        <section @class(['col-lg-8' => $capabilities->canTransferWallet, 'col-12' => !$capabilities->canTransferWallet])>
            <div id="account-wallet-history" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h5 mb-0">{{ __('site.account.wallet.history') }}</h2>
                    </div>
                    <x-tables.controls :paginator="$entries" container-class="card-body" />
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead class="text-nowrap">
                                <tr>
                                    <th data-table-sort="created_at">{{ __('site.account.wallet.date') }}</th>
                                    <th>{{ __('site.account.wallet.reason') }}</th>
                                    <th class="text-end" data-table-sort="amount_minor">{{ __('site.account.wallet.amount') }}</th>
                                    <th class="text-end" data-table-sort="balance_after">{{ __('site.account.wallet.after') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($entries as $entry)
                                    <tr>
                                        <td>{{ app(SiteDates::class)->format($entry->created_at, 'd M Y H:i T') }}
                                        </td>
                                        <td>{{ $entry->transaction->reasonLabel() }}</td>
                                        <td class="text-end text-nowrap">
                                            {{ $entry->amount_minor > 0 ? '+' : '' }}{{ Money::format($entry->amount_minor) }}
                                            {{ $entry->transaction->currency }}</td>
                                        <td class="text-end text-nowrap">
                                            {{ Money::format($entry->balance_after) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-body-secondary p-4">{{ __('site.account.wallet.empty') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">
                        {{ $entries->links() }}
                    </div>
                </div>
            </div>
        </section>
        @if ($capabilities->canTransferWallet)
            <section class="col-lg-4">
                <div class="card card-body p-4">
                    <h2 class="h5 mb-3">{{ __('site.account.wallet.transfer_title') }}</h2>
                    <p class="text-body-secondary small">{{ __('site.account.wallet.transfer_help') }}</p>
                    <form data-ajax="true" data-redirect-url="response" method="post"
                        action="{{ route('account.wallet.transfer') }}">
                        @csrf
                        <input type="hidden" name="request_id" value="{{ $requestId }}">
                        <div class="mb-3">
                            <label class="form-label" for="wallet_recipient">{{ __('site.account.wallet.recipient') }}</label>
                            <input class="form-control" id="wallet_recipient" name="recipient"
                                value="{{ old('recipient') }}" maxlength="40" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="wallet_amount">{{ __('site.account.wallet.amount') }}
                                ({{ $currency }})</label>
                            <input class="form-control" id="wallet_amount" name="amount" value="{{ old('amount') }}"
                                inputmode="decimal" maxlength="30" required>
                            <div class="form-text">
                                {{ __('site.account.wallet.format_help') }}
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="wallet_note">{{ __('site.account.wallet.note') }}</label>
                            <input class="form-control" id="wallet_note" name="note" value="{{ old('note') }}"
                                maxlength="255">
                        </div>
                        @include('partials.forms.confirm-action', ['confirmationId' => 'wallet_transfer'])
                        <button class="btn btn-primary" type="submit">{{ __('site.account.wallet.send') }}</button>
                    </form>
                </div>
            </section>
        @endif
    </div>
@endsection
