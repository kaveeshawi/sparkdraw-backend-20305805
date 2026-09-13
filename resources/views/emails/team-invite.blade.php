<p>Hi {{ $user->name }},</p>

<p>You have been added to your agency workspace on Sparkdraw.</p>

<p>Use these credentials to sign in:</p>

<ul>
  <li><strong>Email:</strong> {{ $user->email }}</li>
  <li><strong>Temporary password:</strong> {{ $temporaryPassword }}</li>
</ul>

<p><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>

<p>After you log in you will land in your member portal. Please change your password from your profile if your agency requires it.</p>

<p>If you did not expect this invitation, contact your agency admin.</p>
