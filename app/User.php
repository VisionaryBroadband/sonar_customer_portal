<?php

namespace App;

use SeanKndy\SonarApi\Resources\Contact;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Lang;

/**
 * Represents a Sonar Customer Portal user which is actually a "Contact" in Sonar itself.
 *
 */
class User implements Authenticatable
{
    use Notifiable;

    public int $id;

    public int $accountId;

    public string $name;

    public string $username;

    public string $emailAddress;

    public static function fromSonarContactResource(Contact $contact): self
    {
        if ($contact->contactableType !== 'Account') {
            throw new \Exception("Only Account Contacts are allowed.");
        }

        $user = new self();
        $user->id = $contact->id;
        $user->name = $contact->name;
        $user->username = $contact->username;
        $user->emailAddress = $contact->emailAddress;
        $user->accountId = $contact->contactableId;

        return $user;
    }

    public function language(): ?string
    {
        if ($usernameLanguage = UsernameLanguage::where('username', $this->username)->first()) {
            return $usernameLanguage->language;
        }

        return null;
    }

    public function getAuthIdentifierName()
    {
        return 'username';
    }

    public function getAuthIdentifier()
    {
        return $this->{$this->getAuthIdentifierName()};
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return null;
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
        //
    }

    public function getRememberTokenName()
    {
        return null;
    }
}
