<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function deny_image_request(): never
{
    http_response_code(404);
    exit;
}

$storageKey = $_GET['key'] ?? null;
if (!ImageStorage::isValidKey($storageKey)) {
    deny_image_request();
}

try {
    $database = database_connection();
    $imageQuery = $database->prepare(
        "SELECT storage_key, image_type, event_id, event_status, entity_status, owner_user_id
         FROM (
           SELECT event.cover_image_key AS storage_key, 'event' AS image_type,
                  event.id AS event_id, event.event_status, 'active' AS entity_status,
                  organizer.owner_user_id
           FROM events AS event
           JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
           WHERE event.cover_image_key = :event_key
           UNION ALL
           SELECT category.image_key, 'category', event.id, event.event_status,
                  category.category_status, organizer.owner_user_id
           FROM categories AS category
           JOIN events AS event ON event.id = category.event_id
           JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
           WHERE category.image_key = :category_key
           UNION ALL
           SELECT contestant.profile_image_key, 'contestant', event.id, event.event_status,
                  contestant.contestant_status, organizer.owner_user_id
           FROM contestants AS contestant
           JOIN events AS event ON event.id = contestant.event_id
           JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
           WHERE contestant.profile_image_key = :contestant_key
           UNION ALL
           SELECT nomination.profile_image_key, 'nomination', event.id, event.event_status,
                  nomination.nomination_status, organizer.owner_user_id
           FROM nominations AS nomination
           JOIN events AS event ON event.id = nomination.event_id
           JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
           WHERE nomination.profile_image_key = :nomination_key
         ) AS images
         WHERE storage_key = :requested_key
         LIMIT 1"
    );
    $imageQuery->execute([
        'event_key' => $storageKey,
        'category_key' => $storageKey,
        'contestant_key' => $storageKey,
        'nomination_key' => $storageKey,
        'requested_key' => $storageKey,
    ]);
    $image = $imageQuery->fetch();
    if (!is_array($image)) {
        deny_image_request();
    }

    $publiclyVisible = $image['event_status'] === 'published'
        && match ($image['image_type']) {
            'event' => true,
            'category', 'contestant' => $image['entity_status'] === 'active',
            'nomination' => $image['entity_status'] === 'approved',
            default => false,
        };
    $canManage = false;
    if (!$publiclyVisible && isset($_SESSION['user'])) {
        $access = new AccessControl($database);
        $user = $access->requireUser();
        $canManage = $access->canManageEvent(
            (int) $user['id'],
            $user['roles'],
            (int) $image['event_id']
        );
    }
    if (!$publiclyVisible && !$canManage) {
        deny_image_request();
    }

    $imagePath = ImageStorage::pathForKey($storageKey);
    if (!is_file($imagePath) || !is_readable($imagePath)) {
        deny_image_request();
    }

    $mimeType = match (pathinfo($storageKey, PATHINFO_EXTENSION)) {
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($imagePath));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="winvote-image"');
    header('Cache-Control: ' . ($publiclyVisible ? 'public' : 'private') . ', max-age=300');
    readfile($imagePath);
} catch (PDOException $exception) {
    error_log('WInVote image lookup database error: ' . $exception->getMessage());
    http_response_code(503);
    exit;
} catch (RuntimeException) {
    deny_image_request();
}
