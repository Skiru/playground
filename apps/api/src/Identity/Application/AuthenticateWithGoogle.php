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
use App\Shared\Application\Clock;
use App\Shared\Application\TransactionManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class AuthenticateWithGoogle
{
    public function __construct(
        private readonly GoogleIdentityVerifier $verifier,
        private readonly ExternalIdentityRepository $externalIdentityRepository,
        private readonly UserRepository $userRepository,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    public function authenticate(string $idToken): User
    {
        // 1. Verify Google identity outside transaction to minimize lock times
        $verifiedIdentity = $this->verifier->verify($idToken);

        try {
            return $this->transactionManager->transactional(function () use ($verifiedIdentity) {
                return $this->doAuthenticate($verifiedIdentity);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent first login race happened. Let's read the identity in a fresh connection/query.
            $identity = $this->externalIdentityRepository->findByProviderAndSubject(
                ExternalIdentityProvider::GOOGLE,
                $verifiedIdentity->subject
            );

            if (null !== $identity) {
                $user = $identity->getUser();
                if (UserStatus::ACTIVE !== $user->status()) {
                    throw new Exception\AccountInactiveException('User account is not active.');
                }

                return $user;
            }

            throw $e;
        }
    }

    private function doAuthenticate(\App\Identity\Domain\Google\VerifiedGoogleIdentity $verifiedIdentity): User
    {
        $now = $this->clock->now();

        // Find ExternalIdentity by GOOGLE + subject
        $identity = $this->externalIdentityRepository->findByProviderAndSubject(
            ExternalIdentityProvider::GOOGLE,
            $verifiedIdentity->subject
        );

        if (null !== $identity) {
            $user = $identity->getUser();
            if (UserStatus::ACTIVE !== $user->status()) {
                throw new Exception\AccountInactiveException('User account is not active.');
            }

            $identity->recordUse($now);
            $user->recordLogin($now);

            $this->externalIdentityRepository->save($identity);
            $this->userRepository->save($user);

            return $user;
        }

        // Email check
        $emailAddress = new EmailAddress($verifiedIdentity->email);
        $existingUser = $this->userRepository->findByEmail($emailAddress);

        if (null !== $existingUser) {
            throw new AccountLinkRequiredException('An account with this email address already exists. Manual linking is required.');
        }

        // Create a new User
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

        $this->userRepository->save($newUser);
        $this->externalIdentityRepository->save($newIdentity);

        return $newUser;
    }
}
