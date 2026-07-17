<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Application\Exception\AccountLinkRequiredException;
use App\Identity\Application\Google\GoogleIdentityVerifier;
use App\Identity\Domain\ExternalIdentity;
use App\Identity\Domain\ExternalIdentityProvider;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use App\Identity\Domain\ValueObject\EmailAddress;

final class AuthenticateWithGoogle
{
    public function __construct(
        private readonly GoogleIdentityVerifier $verifier,
        private readonly ExternalIdentityRepository $externalIdentityRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function authenticate(string $idToken): User
    {
        // 1. Verify Google identity
        $verifiedIdentity = $this->verifier->verify($idToken);
        $now = new \DateTimeImmutable();

        // 2. Find ExternalIdentity by GOOGLE + subject
        $identity = $this->externalIdentityRepository->findByProviderAndSubject(
            ExternalIdentityProvider::GOOGLE,
            $verifiedIdentity->subject
        );

        if (null !== $identity) {
            // 3. User already exists with this google identity
            // Retrieve User
            $user = $identity->getUser();
            if (UserStatus::ACTIVE !== $user->status()) {
                throw new \RuntimeException('User account is not active.');
            }

            // Update usage stats
            $identity->recordUse($now);
            $user->recordLogin($now);

            // Persist
            $this->externalIdentityRepository->save($identity);
            $this->userRepository->save($user);

            return $user;
        }

        // 4. Identity does not exist, check if email is used
        $emailAddress = new EmailAddress($verifiedIdentity->email);
        $existingUser = $this->userRepository->findByEmail($emailAddress);

        if (null !== $existingUser) {
            // 5. Email is already used, block automatic linking to prevent email-takeover
            throw new AccountLinkRequiredException('An account with this email address already exists. Manual linking is required.');
        }

        // 6. No existing identity and no email collision: Create a new User
        $newUser = new User(
            email: $emailAddress,
            displayName: $verifiedIdentity->displayName,
            createdAt: $now,
            roles: ['ROLE_USER']
        );

        // Create new ExternalIdentity
        $newIdentity = new ExternalIdentity(
            user: $newUser,
            provider: ExternalIdentityProvider::GOOGLE,
            providerSubject: $verifiedIdentity->subject,
            providerEmail: $verifiedIdentity->email,
            now: $now
        );

        // Persist
        $this->userRepository->save($newUser);
        $this->externalIdentityRepository->save($newIdentity);

        return $newUser;
    }
}
