USE winvote_dev;
SET time_zone = '+00:00';

INSERT IGNORE INTO roles (name, description) VALUES
  ('super_admin', 'Platform-wide administration'),
  ('organizer', 'Manage owned or assigned events'),
  ('voter', 'Participate in eligible voting events'),
  ('contestant', 'Participate as an approved event contestant');

INSERT IGNORE INTO users (email, password_hash, full_name, account_status) VALUES
  ('admin@winvote.example.test', NULL, 'Jordan Kusi', 'invited'),
  ('organizer@winvote.example.test', NULL, 'Alex Morgan', 'invited'),
  ('voter@winvote.example.test', NULL, 'Kofi Asare', 'invited'),
  ('contestant@winvote.example.test', NULL, 'Ama Mensah', 'invited'),
  ('nominator@winvote.example.test', NULL, 'Nana Osei', 'invited');

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users AS u
JOIN roles AS r ON
  (u.email = 'admin@winvote.example.test' AND r.name = 'super_admin')
  OR (u.email = 'organizer@winvote.example.test' AND r.name = 'organizer')
  OR (u.email = 'voter@winvote.example.test' AND r.name = 'voter')
  OR (u.email = 'contestant@winvote.example.test' AND r.name = 'contestant')
  OR (u.email = 'nominator@winvote.example.test' AND r.name = 'voter');

INSERT IGNORE INTO organizer_profiles (owner_user_id, organization_name, organization_type, support_email, approval_status)
SELECT id, 'Northbridge University', 'University', 'events@northbridge.example.test', 'approved'
FROM users
WHERE email = 'organizer@winvote.example.test';

INSERT IGNORE INTO events (
  organizer_id, created_by, name, slug, event_type, description, event_status,
  voting_mode, result_visibility, starts_at, ends_at, max_votes_per_voter,
  price_per_vote, currency, nomination_opens_at, nomination_closes_at, approved_by, approved_at
)
SELECT
  op.id, u.id, 'Campus Voices Awards', 'campus-voices-awards-2026',
  'University awards',
  'Development seed event for previewing categories, nominations, contestants and voting records.',
  'published', 'free_multiple', 'live',
  '2026-11-12 00:00:00', '2026-11-28 23:59:59', 5,
  0.00, 'GHS', '2026-10-20 00:00:00', '2026-11-05 23:59:59',
  (SELECT id FROM users WHERE email = 'admin@winvote.example.test' LIMIT 1),
  '2026-10-29 12:00:00'
FROM organizer_profiles AS op
JOIN users AS u ON u.id = op.owner_user_id
WHERE u.email = 'organizer@winvote.example.test';

INSERT IGNORE INTO categories (event_id, name, description)
SELECT e.id, category.name, category.description
FROM events AS e
CROSS JOIN (
  SELECT 'Community builder' AS name, 'Recognizing people who bring their community together.' AS description
  UNION ALL SELECT 'Creative voice', 'Celebrating creativity and original expression.'
  UNION ALL SELECT 'Student initiative', 'Highlighting student-led projects and initiatives.'
) AS category
WHERE e.slug = 'campus-voices-awards-2026';

INSERT IGNORE INTO nominations (
  event_id, category_id, submitted_by, applicant_name, applicant_email, nominee_name,
  nominee_email, biography, nomination_status, reviewed_by, reviewed_at
)
SELECT e.id, c.id, submitter.id, 'Nana Osei', 'nominator@winvote.example.test',
  'Ama Mensah', 'contestant@winvote.example.test',
  'A student organizer who helps people connect through welcoming campus projects.',
  'approved', admin.id, '2026-10-29 12:30:00'
FROM events AS e
JOIN categories AS c ON c.event_id = e.id AND c.name = 'Community builder'
JOIN users AS submitter ON submitter.email = 'nominator@winvote.example.test'
JOIN users AS admin ON admin.email = 'admin@winvote.example.test'
WHERE e.slug = 'campus-voices-awards-2026'
  AND NOT EXISTS (
    SELECT 1 FROM nominations AS existing
    WHERE existing.event_id = e.id
      AND existing.category_id = c.id
      AND existing.nominee_name = 'Ama Mensah'
  );

INSERT IGNORE INTO nominations (
  event_id, category_id, submitted_by, applicant_name, applicant_email, nominee_name,
  nominee_email, biography, nomination_status
)
SELECT e.id, c.id, submitter.id, 'Mina Boakye', 'nominator@winvote.example.test',
  'Kojo Owusu', NULL,
  'A peer mentor and community volunteer at Northbridge University.',
  'pending'
FROM events AS e
JOIN categories AS c ON c.event_id = e.id AND c.name = 'Community builder'
JOIN users AS submitter ON submitter.email = 'nominator@winvote.example.test'
WHERE e.slug = 'campus-voices-awards-2026'
  AND NOT EXISTS (
    SELECT 1 FROM nominations AS existing
    WHERE existing.event_id = e.id
      AND existing.category_id = c.id
      AND existing.nominee_name = 'Kojo Owusu'
  );

INSERT IGNORE INTO contestants (event_id, nomination_id, created_by, display_name, biography, contestant_status, public_vote_counts)
SELECT e.id, n.id, organizer.id, n.nominee_name, n.biography, 'active', 0
FROM events AS e
JOIN nominations AS n ON n.event_id = e.id
JOIN users AS organizer ON organizer.email = 'organizer@winvote.example.test'
WHERE e.slug = 'campus-voices-awards-2026'
  AND n.nominee_name = 'Ama Mensah'
  AND n.nomination_status = 'approved';

INSERT IGNORE INTO contestants (event_id, created_by, display_name, biography, contestant_status, public_vote_counts)
SELECT e.id, organizer.id, 'Esi Sarfo',
  'A creative student voice and contributor to campus arts events.', 'active', 0
FROM events AS e
JOIN users AS organizer ON organizer.email = 'organizer@winvote.example.test'
WHERE e.slug = 'campus-voices-awards-2026'
  AND NOT EXISTS (
    SELECT 1 FROM contestants AS existing
    WHERE existing.event_id = e.id AND existing.display_name = 'Esi Sarfo'
  );

INSERT IGNORE INTO contestant_categories (event_id, category_id, contestant_id)
SELECT e.id, c.id, contestant.id
FROM events AS e
JOIN categories AS c ON c.event_id = e.id AND c.name = 'Community builder'
JOIN contestants AS contestant ON contestant.event_id = e.id
WHERE e.slug = 'campus-voices-awards-2026'
  AND contestant.display_name IN ('Ama Mensah', 'Esi Sarfo');

INSERT IGNORE INTO voter_eligibility (event_id, user_id, eligibility_status, verified_at)
SELECT e.id, voter.id, 'eligible', '2026-10-29 13:00:00'
FROM events AS e
JOIN users AS voter ON voter.email = 'voter@winvote.example.test'
WHERE e.slug = 'campus-voices-awards-2026';

INSERT IGNORE INTO notifications (user_id, notification_type, title, message)
SELECT id, 'event_update', 'Welcome to WInVote',
  'This is a development-only sample notification.'
FROM users
WHERE email = 'organizer@winvote.example.test';

INSERT IGNORE INTO support_tickets (
  ticket_reference, requester_user_id, requester_name, requester_email,
  topic, subject, message, ticket_status
)
SELECT 'WV-DEMO-0001', id, full_name, email, 'organizer_support',
  'Development seed support request',
  'Sample support request for previewing the administrator support queue.',
  'open'
FROM users
WHERE email = 'organizer@winvote.example.test';
