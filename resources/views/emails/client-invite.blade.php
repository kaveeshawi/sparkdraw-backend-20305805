<p>Hi {{ $user->name }},</p>

<p>You have been invited to access the client portal for <strong>{{ $client->company_name }}</strong> on Sparkdraw.</p>

<p>Click the link below to set your password and activate your account:</p>

<p><a href="{{ $inviteUrl }}">{{ $inviteUrl }}</a></p>

<p>This link expires in 48 hours.</p>

<p>If you did not expect this invitation, you can ignore this email.</p>
