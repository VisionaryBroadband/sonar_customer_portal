<?php

namespace App\Extensions;

use SeanKndy\SonarApi\Client;
use App\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SonarSoftware\CustomerPortalFramework\Controllers\AccountAuthenticationController;
use SonarSoftware\CustomerPortalFramework\Exceptions\AuthenticationException;

class SonarUserProvider implements UserProvider
{
    private Client $sonarClient;
    /**
     * This is required for password validation because that is currently not possible via GraphQL.
     */
    private AccountAuthenticationController $accountAuthenticationController;

    public function __construct(
        Client $sonarClient,
        AccountAuthenticationController $accountAuthenticationController
    ) {
        $this->sonarClient = $sonarClient;
        $this->accountAuthenticationController = $accountAuthenticationController;
    }
    
    public function retrieveById($identifier)
    {
        if ($cachedUser = Cache::tags('users')->get($identifier)) {
            return $cachedUser;
        }

        $contact = $this->sonarClient->contacts()
            ->where('username', $identifier)
            ->where('contactable_type', 'Account')
            ->first();

        if (!$contact) {
            return null;
        }

        return User::fromSonarContactResource($contact);
    }

    public function retrieveByToken($identifier, $token)
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token)
    {
        //
    }

    public function retrieveByCredentials(array $credentials)
    {
        return $this->retrieveById($credentials['username']);
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        if (empty($credentials['password'])) {
            return false;
        }

        try {
            $this->accountAuthenticationController->authenticateUser(
                $user->getAuthIdentifier(),
                $credentials['password']
            );
            return true;
        } catch (AuthenticationException $e) {
            return false;
        }
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false)
    {
        // Passwords are stored in Sonar, not locally.
    }
}