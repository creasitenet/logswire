<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Business logic for user lifecycle: creation and password reset.
 */
final class UserService
{
    /** Minimum length for CLI-created local accounts (no public signup). */
    public const int MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @throws \InvalidArgumentException if the username or password is invalid
     * @throws ConflictHttpException     if the username is already taken
     */
    public function create(string $username, string $plainPassword, bool $admin = false): User
    {
        $username = strtolower(trim($username));
        $this->guardUsername($username);
        $this->guardPassword($plainPassword);

        if ($this->users->findOneByUsername($username) !== null) {
            throw new ConflictHttpException(sprintf('User "%s" already exists.', $username));
        }

        // Bootstrap rule: first user is auto-promoted so the install is usable without manual intervention.
        $isFirst = $this->users->count([]) === 0;
        $grantAdmin = $admin || $isFirst;

        $user = (new User())
            ->setUsername($username)
            ->setRoles($grantAdmin ? ['ROLE_ADMIN', 'ROLE_USER'] : ['ROLE_USER'])
            ->setPassword($this->hasher->hashPassword(new User(), $plainPassword));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function resetPassword(User $user, string $plainPassword): User
    {
        $this->guardPassword($plainPassword);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->em->flush();

        return $user;
    }

    private function guardUsername(string $username): void
    {
        if ($username === '' || \strlen($username) > 64) {
            throw new \InvalidArgumentException('Username must be 1-64 chars.');
        }
        if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
            throw new \InvalidArgumentException('Username can only contain lowercase letters, digits, dot, underscore, hyphen.');
        }
    }

    private function guardPassword(string $password): void
    {
        if (\strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Password must be at least %d characters.',
                self::MIN_PASSWORD_LENGTH,
            ));
        }
    }
}
