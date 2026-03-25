@component('mail::message')
# {{ $subjectLine }}

{!! \Illuminate\Mail\Markdown::parse($markdownBody) !!}
@endcomponent

