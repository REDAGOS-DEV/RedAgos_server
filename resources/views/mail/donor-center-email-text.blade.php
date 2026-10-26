@foreach ($paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
--
{!! $centerName !!}
@if ($centerAddress)
{!! $centerAddress !!}
@endif
@if ($centerPhone)
{!! $centerPhone !!}
@endif
@if ($centerEmail)
{!! $centerEmail !!}
@endif
