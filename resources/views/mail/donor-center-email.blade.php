<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
{{--
    The centre's own email (App\Mail\DonorCenterEmail). Inline styles only:
    most mail clients drop <style> blocks. Every paragraph is plain text from
    the centre's template, escaped here, so a template cannot carry markup.
--}}
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e4e7eb;border-radius:10px;">
                    <tr>
                        <td style="padding:20px 28px;border-bottom:1px solid #e4e7eb;">
                            <p style="margin:0;font-size:17px;font-weight:bold;color:#1f2933;">{{ $centerName }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px 8px;">
                            @foreach ($paragraphs as $paragraph)
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#1f2933;">{!! nl2br(e($paragraph)) !!}</p>
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 22px;border-top:1px solid #e4e7eb;font-size:12px;line-height:1.6;color:#616e7c;">
                            <strong style="color:#3e4c59;">{{ $centerName }}</strong>
                            @if ($centerAddress)<br>{{ $centerAddress }}@endif
                            @if ($centerPhone)<br>{{ $centerPhone }}@endif
                            @if ($centerEmail)<br>{{ $centerEmail }}@endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
