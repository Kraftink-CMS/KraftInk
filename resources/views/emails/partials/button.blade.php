<table role="presentation" class="mail-action" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td class="mail-button-cell"><a class="mail-button" href="{{ $actionUrl }}">{{ $action }}</a></td>
    </tr>
</table>
<p class="mail-link-help">{{ __('site.auth.mail.link_help', [], $locale ?? app()->getLocale()) }}<br>
    <a class="mail-link" href="{{ $actionUrl }}">{{ $actionUrl }}</a>
</p>
