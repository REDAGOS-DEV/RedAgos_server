<x-mail::message>
# Verify your email address

Enter this code in RedAgos to confirm your email address:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

The code expires in {{ $minutes }} minutes. Never share it with anyone, including RedAgos staff.

If you did not ask for this code, you can ignore this email.

Regards,<br>
{{ config('app.name') }}
</x-mail::message>
