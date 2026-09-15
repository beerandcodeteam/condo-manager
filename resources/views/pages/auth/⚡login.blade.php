<?php

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Entrar')] class extends Component
{
    public const MAX_ATTEMPTS_PER_MINUTE = 5;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * Authenticate an active panel user.
     */
    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
            'is_active' => true,
        ];

        if (! Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha inválidos.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        session()->regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false));
    }

    /**
     * Block the attempt once the e-mail + IP pair exceeded the per-minute limit.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS_PER_MINUTE)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
};
?>

<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-1">
        <h1 class="text-[17px] font-semibold tracking-[-0.01em]">Entrar no painel</h1>
        <p class="text-[13px] text-ink-secondary">Use o e-mail e a senha cadastrados.</p>
    </div>

    <form wire:submit="login" class="flex flex-col gap-4">
        <x-ui.field label="E-mail" name="email" for="email">
            <x-ui.input id="email" type="email" wire:model="email" autocomplete="email" autofocus required />
        </x-ui.field>

        <x-ui.field label="Senha" name="password" for="password">
            <x-ui.input id="password" type="password" wire:model="password" autocomplete="current-password" required />
        </x-ui.field>

        <label for="remember" class="flex items-center gap-2 text-[13px] text-ink-body">
            <input id="remember" type="checkbox" wire:model="remember" class="size-4 rounded border-line-strong accent-accent">
            Lembrar de mim
        </label>

        <x-ui.button type="submit" loading="login" class="w-full">Entrar</x-ui.button>
    </form>
</div>
