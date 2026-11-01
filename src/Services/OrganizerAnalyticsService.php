<?php
declare(strict_types=1);

final class OrganizerAnalyticsService
{
    public function __construct(
        private readonly PDO $database,
        private readonly AccessControl $access
    ) {
    }

    public function eventInsights(int $eventId, int $userId, array $roles): array
    {
        if ($eventId < 1) {
            throw new InvalidArgumentException('Choose a valid event.');
        }
        if (!$this->access->canManageEvent($userId, $roles, $eventId)) {
            http_response_code(404);
            throw new RuntimeException('The event was not found or you do not have access to it.');
        }

        $eventQuery = $this->database->prepare(
            'SELECT id, name, event_status, voting_mode, result_visibility,
                    starts_at, ends_at, nomination_opens_at, nomination_closes_at, currency
             FROM events
             WHERE id = :event_id
             LIMIT 1'
        );
        $eventQuery->execute(['event_id' => $eventId]);
        $event = $eventQuery->fetch();
        if (!is_array($event)) {
            http_response_code(404);
            throw new RuntimeException('The event could not be found.');
        }

        $totalsQuery = $this->database->prepare(
            "SELECT COUNT(vote.id) AS ballot_records,
                    COUNT(DISTINCT vote.voter_user_id) AS unique_voters,
                    COALESCE(SUM(vote.vote_quantity), 0) AS total_votes
             FROM votes AS vote
             WHERE vote.event_id = :event_id AND vote.vote_status = 'credited'"
        );
        $totalsQuery->execute(['event_id' => $eventId]);
        $totals = $totalsQuery->fetch();

        $paymentQuery = $this->database->prepare(
            "SELECT COUNT(CASE WHEN transaction_record.transaction_status = 'successful' THEN 1 END)
                        AS successful_transactions,
                    COALESCE(SUM(
                        CASE WHEN transaction_record.transaction_status = 'successful'
                             THEN transaction_record.amount ELSE 0 END
                    ), 0) AS simulated_gross,
                    COUNT(CASE WHEN transaction_record.transaction_status = 'pending' THEN 1 END)
                        AS pending_transactions
             FROM transactions AS transaction_record
             WHERE transaction_record.event_id = :event_id
               AND EXISTS (
                   SELECT 1
                   FROM payment_attempts AS payment_attempt
                   WHERE payment_attempt.transaction_id = transaction_record.id
                     AND payment_attempt.provider = 'local_simulator'
               )"
        );
        $paymentQuery->execute(['event_id' => $eventId]);
        $payments = $paymentQuery->fetch();

        $standingsQuery = $this->database->prepare(
            "SELECT category.id AS category_id, category.name AS category_name,
                    category.image_key AS category_image_key,
                    contestant.id AS contestant_id, contestant.display_name,
                    contestant.profile_image_key,
                    COALESCE(SUM(vote.vote_quantity), 0) AS vote_count
             FROM categories AS category
             JOIN contestant_categories AS assignment
               ON assignment.category_id = category.id AND assignment.event_id = category.event_id
             JOIN contestants AS contestant
               ON contestant.id = assignment.contestant_id
              AND contestant.event_id = assignment.event_id
             LEFT JOIN votes AS vote
               ON vote.event_id = assignment.event_id
              AND vote.category_id = assignment.category_id
              AND vote.contestant_id = assignment.contestant_id
              AND vote.vote_status = 'credited'
             WHERE category.event_id = :event_id
               AND category.category_status = 'active'
               AND contestant.contestant_status = 'active'
             GROUP BY category.id, category.name, category.image_key,
                      contestant.id, contestant.display_name, contestant.profile_image_key
             ORDER BY category.name, vote_count DESC, contestant.display_name"
        );
        $standingsQuery->execute(['event_id' => $eventId]);

        $nominationQuery = $this->database->prepare(
            'SELECT nomination_status, COUNT(*) AS nomination_count
             FROM nominations
             WHERE event_id = :event_id
             GROUP BY nomination_status'
        );
        $nominationQuery->execute(['event_id' => $eventId]);
        $nominations = [];
        foreach ($nominationQuery->fetchAll() as $row) {
            $nominations[(string) $row['nomination_status']] = (int) $row['nomination_count'];
        }

        $trendQuery = $this->database->prepare(
            "SELECT DATE(cast_at) AS vote_date, SUM(vote_quantity) AS vote_count
             FROM votes
             WHERE event_id = :event_id
               AND vote_status = 'credited'
               AND cast_at >= UTC_DATE() - INTERVAL 13 DAY
             GROUP BY DATE(cast_at)
             ORDER BY vote_date"
        );
        $trendQuery->execute(['event_id' => $eventId]);
        $dailyTotals = [];
        foreach ($trendQuery->fetchAll() as $row) {
            $dailyTotals[(string) $row['vote_date']] = (int) $row['vote_count'];
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $trend = [];
        for ($offset = 13; $offset >= 0; $offset--) {
            $date = $today->modify('-' . $offset . ' days');
            $key = $date->format('Y-m-d');
            $trend[] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'vote_count' => $dailyTotals[$key] ?? 0,
            ];
        }

        return [
            'event' => $event,
            'totals' => $totals,
            'payments' => $payments,
            'standings' => $standingsQuery->fetchAll(),
            'nominations' => $nominations,
            'trend' => $trend,
        ];
    }
}
