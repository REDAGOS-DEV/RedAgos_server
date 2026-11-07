@props(['url'])
{{--
    The logo every RedAgos email opens with (config mail.logo_url). The alt is
    empty because the name follows as text, which also still shows where a
    client holds back linked images.

    Links to the portal: $url is the API's address, which has nothing to visit.
--}}
<tr>
<td class="header">
<a href="{{ config('app.frontend_url') }}" style="display: inline-block; text-decoration: none;">
<img src="{{ config('mail.logo_url') }}" width="72" height="72" alt="" style="display: block; width: 72px; height: 72px; margin: 0 auto 10px;">
<span style="display: block; font-size: 24px; font-weight: 800; line-height: 1.2; color: #0B1F3A;">Red<span style="color: #C62828;">Agos</span></span>
<span style="display: block; margin-top: 2px; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #64748B;">Blood Bank System</span>
</a>
</td>
</tr>
