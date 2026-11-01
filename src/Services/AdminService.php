<?php
declare(strict_types=1);

final class AdminService
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function overview(): array
    {
        return [
            'pending_organizers' => $this->count(
                "SELECT COUNT(*) FROM organizer_profiles WHERE approval_status = 'pending'"
            ),
            'pending_events' => $this->count(
                "SELECT COUNT(*) FROM events WHERE event_status = 'pending_review'"
            ),
            'active_users' => $this->count(
                "SELECT COUNT(*) FROM users WHERE account_status = 'active'"
            ),
            'total_users' => $this->count('SELECT COUNT(*) FROM users'),
            'total_organizers' => $this->count('SELECT COUNT(*) FROM organizer_profiles'),
            'approved_organizers' => $this->count(
                "SELECT COUNT(*) FROM organizer_profiles WHERE approval_status = 'approved'"
            ),
            'total_events' => $this->count('SELECT COUNT(*) FROM events'),
            'published_events' => $this->count(
                "SELECT COUNT(*) FROM events WHERE event_status = 'published'"
            ),
            'active_nominees' => $this->count(
                "SELECT COUNT(*) FROM contestants WHERE contestant_status = 'active'"
            ),
            'credited_votes' => $this->count(
                "SELECT COALESCE(SUM(vote_quantity), 0) FROM votes WHERE vote_status = 'credited'"
            ),
        ];
    }

    public function allOrganizers(): array
    {
        return $this->database->query(
            'SELECT organizer.id AS organizer_id, organizer.organization_name,
                    organizer.organization_type, organizer.approval_status,
                    organizer.created_at, user.full_name, user.email, user.account_status,
                    (SELECT COUNT(*) FROM events AS event
                     WHERE event.organizer_id = organizer.id) AS event_count
             FROM organizer_profiles AS organizer
             JOIN users AS user ON user.id = organizer.owner_user_id
             ORDER BY organizer.created_at DESC, organizer.id DESC
             LIMIT 100'
        )->fetchAll();
    }

    public function allEvents(): array
    {
        return $this->database->query(
            "SELECT event.id, event.name, event.event_type, event.event_status,
                    event.voting_mode, event.starts_at, event.ends_at,
                    organizer.organization_name, user.full_name AS organizer_name,
                    (SELECT COUNT(*) FROM categories AS category
                     WHERE category.event_id = event.id
                       AND category.category_status = 'active') AS category_count,
                    (SELECT COUNT(DISTINCT contestant.id)
                     FROM contestants AS contestant
                     JOIN contestant_categories AS assignment
                       ON assignment.contestant_id = contestant.id
                      AND assignment.event_id = contestant.event_id
                     JOIN categories AS category
                       ON category.id = assignment.category_id
                      AND category.event_id = assignment.event_id
                     WHERE contestant.event_id = event.id
                       AND contestant.contestant_status = 'active'
                       AND category.category_status = 'active') AS nominee_count,
                    (SELECT COALESCE(SUM(vote.vote_quantity), 0)
                     FROM votes AS vote
                     WHERE vote.event_id = event.id
                       AND vote.vote_status = 'credited') AS credited_votes
             FROM events AS event
             JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
             JOIN users AS user ON user.id = organizer.owner_user_id
             ORDER BY event.created_at DESC, event.id DESC
             LIMIT 100"
        )->fetchAll();
    }

    public function allUsers(): array
    {
        return $this->database->query(
            "SELECT user.id, user.full_name, user.email, user.phone,
                    user.account_status, user.created_at, user.last_login_at,
                    GROUP_CONCAT(DISTINCT role.name ORDER BY role.name SEPARATOR ', ') AS roles,
                    organizer.approval_status AS organizer_status
             FROM users AS user
             LEFT JOIN user_roles AS user_role ON user_role.user_id = user.id
             LEFT JOIN roles AS role ON role.id = user_role.role_id
             LEFT JOIN organizer_profiles AS organizer
               ON organizer.owner_user_id = user.id
             GROUP BY user.id, user.full_name, user.email, user.phone,
                      user.account_status, user.created_at, user.last_login_at,
                      organizer.approval_status
             ORDER BY user.created_at DESC, user.id DESC
             LIMIT 100"
        )->fetchAll();
    }

    public function recentActivity(): array
    {
        return $this->database->query(
            'SELECT audit.action, audit.resource_type, audit.resource_id,
                    audit.outcome, audit.created_at, user.full_name AS actor_name
             FROM audit_logs AS audit
             LEFT JOIN users AS user ON user.id = audit.actor_user_id
             ORDER BY audit.created_at DESC, audit.id DESC
             LIMIT 8'
        )->fetchAll();
    }

    public function pendingOrganizers(): array
    {
        $statement = $this->database->query(
            "SELECT organizer.id AS organizer_id, organizer.organization_name,
                    organizer.organization_type, organizer.support_email,
                    organizer.support_phone, organizer.created_at,
                    user.id AS user_id, user.full_name, user.email
             FROM organizer_profiles AS organizer
             JOIN users AS user ON user.id = organizer.owner_user_id
             WHERE organizer.approval_status = 'pending'
               AND user.account_status = 'active'
             ORDER BY organizer.created_at, organizer.id"
        );

        return $statement->fetchAll();
    }

    public function pendingEvents(): array
    {
        $statement = $this->database->query(
            "SELECT event.id, event.name, event.event_type, event.description,
                    event.voting_mode, event.price_per_vote, event.currency,
                    event.starts_at, event.ends_at, event.created_at,
                   (SELECT COUNT(*) FROM categories AS category
                    WHERE category.event_id = event.id
                      AND category.category_status = 'active') AS category_count,
                   (SELECT COUNT(DISTINCT contestant.id)
                    FROM contestants AS contestant
                    JOIN contestant_categories AS assignment
                      ON assignment.contestant_id = contestant.id
                     AND assignment.event_id = contestant.event_id
                    JOIN categories AS category
                      ON category.id = assignment.category_id
                     AND category.event_id = assignment.event_id
                    WHERE contestant.event_id = event.id
                      AND contestant.contestant_status = 'active'
                      AND category.category_status = 'active') AS nominee_count,
                   organizer.organization_name, user.full_name, user.email
             FROM events AS event
             JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
             JOIN users AS user ON user.id = organizer.owner_user_id
             WHERE event.event_status = 'pending_review'
               AND organizer.approval_status = 'approved'
               AND user.account_status = 'active'
             ORDER BY event.created_at, event.id"
        );

        return $statement->fetchAll();
    }

    public function reviewOrganizer(int $organizerId, int $adminId, string $decision): void
    {
        if ($organizerId < 1 || $adminId < 1
            || !in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose a valid organizer review decision.');
        }

        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'SELECT organizer.approval_status, user.account_status
                 FROM organizer_profiles AS organizer
                 JOIN users AS user ON user.id = organizer.owner_user_id
                 WHERE organizer.id = :organizer_id
                 FOR UPDATE'
            );
            $statement->execute(['organizer_id' => $organizerId]);
            $organizer = $statement->fetch();
            if (!is_array($organizer)
                || $organizer['approval_status'] !== 'pending'
                || $organizer['account_status'] !== 'active') {
                throw new DomainException('This organizer is no longer awaiting review.');
            }

            $update = $this->database->prepare(
                'UPDATE organizer_profiles
                 SET approval_status = :approval_status,
                     approved_by = :approved_by,
                     approved_at = :approved_at
                 WHERE id = :organizer_id'
            );
            $update->execute([
                'approval_status' => $decision,
                'approved_by' => $decision === 'approved' ? $adminId : null,
                'approved_at' => $decision === 'approved' ? gmdate('Y-m-d H:i:s') : null,
                'organizer_id' => $organizerId,
            ]);

            $this->recordAudit(
                $adminId,
                'organizer.' . $decision,
                'organizer',
                $organizerId,
                ['approval_status' => $decision]
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function reviewEvent(int $eventId, int $adminId, string $decision): void
    {
        if ($eventId < 1 || $adminId < 1
            || !in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose a valid event review decision.');
        }

        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'SELECT event.event_status, event.voting_mode,
                        organizer.approval_status, user.account_status
                 FROM events AS event
                 JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
                 JOIN users AS user ON user.id = organizer.owner_user_id
                 WHERE event.id = :event_id
                 FOR UPDATE'
            );
            $statement->execute(['event_id' => $eventId]);
            $event = $statement->fetch();
            if (!is_array($event) || $event['event_status'] !== 'pending_review') {
                throw new DomainException('This event is no longer awaiting review.');
            }
            if ($event['approval_status'] !== 'approved' || $event['account_status'] !== 'active') {
                throw new DomainException('The event organizer must be active and approved before review.');
            }
            if ($decision === 'approved') {
                $ballotQuery = $this->database->prepare(
                    "SELECT EXISTS (
                       SELECT 1
                       FROM categories AS category
                       JOIN contestant_categories AS assignment
                         ON assignment.event_id = category.event_id
                        AND assignment.category_id = category.id
                       JOIN contestants AS contestant
                         ON contestant.id = assignment.contestant_id
                        AND contestant.event_id = assignment.event_id
                       WHERE category.event_id = :event_id
                         AND category.category_status = 'active'
                         AND contestant.contestant_status = 'active'
                     )"
                );
                $ballotQuery->execute(['event_id' => $eventId]);
                if (!(bool) $ballotQuery->fetchColumn()) {
                    throw new DomainException(
                        'Add at least one active category and nominee before approving this event.'
                    );
                }
            }
            if ($decision === 'approved'
                && $event['voting_mode'] === 'paid_multiple'
                && !PaymentSimulator::isAvailableForRequest()) {
                throw new DomainException(
                    'Paid-voting events require a verified payment provider. Only the local payment simulator can publish them in local development.'
                );
            }

            $status = $decision === 'approved' ? 'published' : 'rejected';
            $update = $this->database->prepare(
                'UPDATE events
                 SET event_status = :event_status,
                     approved_by = :approved_by,
                     approved_at = :approved_at
                 WHERE id = :event_id'
            );
            $update->execute([
                'event_status' => $status,
                'approved_by' => $decision === 'approved' ? $adminId : null,
                'approved_at' => $decision === 'approved' ? gmdate('Y-m-d H:i:s') : null,
                'event_id' => $eventId,
            ]);

            $this->recordAudit(
                $adminId,
                'event.' . $decision,
                'event',
                $eventId,
                ['event_status' => $status]
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    private function count(string $query): int
    {
        return (int) $this->database->query($query)->fetchColumn();
    }

    private function recordAudit(
        int $adminId,
        string $action,
        string $resourceType,
        int $resourceId,
        array $details
    ): void {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            $remoteAddress = null;
        }

        $statement = $this->database->prepare(
            "INSERT INTO audit_logs (
                actor_user_id, action, resource_type, resource_id,
                outcome, details, ip_address
             ) VALUES (
                :actor_user_id, :action, :resource_type, :resource_id,
                'success', :details, INET6_ATON(:ip_address)
             )"
        );
        $statement->execute([
            'actor_user_id' => $adminId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'ip_address' => $remoteAddress,
        ]);
    }
}
