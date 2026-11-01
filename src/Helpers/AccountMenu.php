<?php
declare(strict_types=1);

function render_account_menu(array $user, string $csrfToken, string $basePath, string $roleLabel): string
{
    $escape = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $fullName = (string) ($user['full_name'] ?? 'WInVote account');
    $initial = mb_strtoupper(mb_substr($fullName, 0, 1, 'UTF-8'), 'UTF-8');
    $email = (string) ($user['email'] ?? '');
    $token = $escape($csrfToken);

    return '<details class="account-menu">'
        . '<summary class="account-trigger"><span class="user-avatar" aria-hidden="true">'
        . $escape($initial) . '</span><span class="account-trigger-name">'
        . $escape($fullName) . '</span><span aria-hidden="true">⌄</span></summary>'
        . '<div class="account-dropdown"><strong>' . $escape($fullName) . '</strong>'
        . '<span class="account-email">' . $escape($email) . '</span>'
        . '<span class="account-role">' . $escape($roleLabel) . '</span>'
        . '<a href="' . $escape($basePath) . 'auth.php?action=account">Account details</a>'
        . '<form method="post" action="' . $escape($basePath) . 'auth.php?action=logout">'
        . '<input type="hidden" name="_token" value="' . $token . '">'
        . '<button type="submit">Sign out</button></form></div></details>';
}
