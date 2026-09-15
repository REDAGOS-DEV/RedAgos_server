<?php

namespace App\Console\Commands\Concerns;

use App\Support\AccountIdentity;
use Illuminate\Support\Str;

/**
 * Option-or-prompt handling shared by the account bootstrap commands.
 *
 * All three exist to create an account that cannot be created over HTTP yet,
 * and all three are run both by hand and from a script, so each field is either
 * passed as an option or asked for. Keeping one copy is what stops them
 * drifting on the details that decide whether the account can sign in
 * afterwards — a lower-cased address and an E.164 phone number are what the
 * unique indexes and the login lookup are written against.
 *
 * Nothing here validates. The commands do that against the same rules the API
 * uses, so a console-created account is the same account the portal would have
 * made.
 */
trait PromptsForAccountDetails
{
    /**
     * Read an option, asking for it when it was not supplied.
     *
     * A --no-interaction run cannot answer, so the prompt yields an empty
     * string and the caller's validator refuses it by name. That is a better
     * message than anything a guard here could produce.
     */
    protected function optionOrAsk(string $option, string $label): string
    {
        $value = trim((string) $this->option($option));

        return $value !== '' ? $value : trim((string) $this->ask($label));
    }

    /**
     * Read an optional option, returning null rather than an empty string.
     *
     * Never prompts: a field the account does not need should not be a question
     * the operator has to dismiss.
     */
    protected function optionOrNull(string $option): ?string
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : $value;
    }

    /**
     * Read a password option, asking for it twice when it was not supplied.
     *
     * Only what was typed blind is worth confirming. A password passed as an
     * argument is on screen already, and asking twice for it cannot fail.
     *
     * Returns null when the two entries disagree.
     */
    protected function passwordOrAsk(string $option = 'password', string $label = 'Password'): ?string
    {
        $supplied = trim((string) $this->option($option));

        if ($supplied !== '') {
            return $supplied;
        }

        $password = trim((string) $this->secret($label));

        if ($password !== trim((string) $this->secret('Confirm '.Str::lower($label)))) {
            $this->error('The passwords did not match.');

            return null;
        }

        return $password;
    }

    /**
     * Read an email option and lower-case it.
     *
     * Sign-in matches on a lower-cased address, so an account created as
     * Staff@Example.com would never be found by its own credentials.
     */
    protected function emailOrAsk(string $option, string $label): string
    {
        return Str::lower($this->optionOrAsk($option, $label));
    }

    /**
     * Read a phone option and normalise it to E.164, the stored form.
     *
     * Checking a raw "09..." against the column would never match an existing
     * "+639..." one, so a duplicate would surface as a database error instead
     * of a field-level message.
     */
    protected function phoneOrAsk(string $option, string $label): string
    {
        return AccountIdentity::normalizePhilippinePhone($this->optionOrAsk($option, $label));
    }
}
