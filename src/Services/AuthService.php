<?php
declare(strict_types=1);

final class AuthService
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function register(
        string $email,
        string $passwordHash,
        string $fullName,
        string $accountType
    ): void {
        if (!in_array($accountType, ['voter', 'organizer'], true)) {
            throw new InvalidArgumentException('Choose a supported account type.');
        }

        try {
            $this->database->beginTransaction();

            $insertUser = $this->database->prepare(
                'INSERT INTO users (email, password_hash, full_name, account_status)
                 VALUES (:email, :password_hash, :full_name, :account_status)'
            );
            $insertUser->execute([
                'email' => $email,
                'password_hash' => $passwordHash,
                'full_name' => $fullName,
                'account_status' => 'active',
            ]);
            $userId = (int) $this->database->lastInsertId();

            $roleName = $accountType === 'organizer' ? 'organizer' : 'voter';
            $assignRole = $this->database->prepare(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT :user_id, id FROM roles WHERE name = :role_name'
            );
            $assignRole->execute([
                'user_id' => $userId,
                'role_name' => $roleName,
            ]);

            if ($assignRole->rowCount() !== 1) {
                throw new RuntimeException('The requested account role is not configured.');
            }

            if ($accountType === 'organizer') {
                $createProfile = $this->database->prepare(
                    "INSERT INTO organizer_profiles (owner_user_id, organization_name, approval_status)
                     VALUES (:owner_user_id, :organization_name, 'pending')"
                );
                $createProfile->execute([
                    'owner_user_id' => $userId,
                    'organization_name' => $fullName,
                ]);
            }

            $this->database->commit();
        } catch (PDOException $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('An account with that email address already exists.', 0, $exception);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    public function authenticate(string $email, string $password): ?array
    {
        $statement = $this->database->prepare(
            "SELECT u.id, u.email, u.full_name, u.password_hash,
                    u.account_status, op.approval_status AS organizer_status,
                    GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ',') AS roles
             FROM users AS u
             LEFT JOIN organizer_profiles AS op ON op.owner_user_id = u.id
             LEFT JOIN user_roles AS ur ON ur.user_id = u.id
             LEFT JOIN roles AS r ON r.id = ur.role_id
             WHERE u.email = :email
             GROUP BY u.id, u.email, u.full_name, u.password_hash,
                      u.account_status, op.approval_status
             LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if (!is_array($user)
            || $user['account_status'] !== 'active'
            || !is_string($user['password_hash'])
            || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->database->prepare(
                'UPDATE users SET password_hash = :password_hash WHERE id = :id'
            );
            $rehash->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $user['id'],
            ]);
        }

        $updateLogin = $this->database->prepare(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $updateLogin->execute(['id' => $user['id']]);

        unset($user['password_hash']);
        return $user;
    }
}
