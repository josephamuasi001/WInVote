<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function post_string(string $name, string $default, string $message): string
{
    $value = $_POST[$name] ?? $default;
    if (!is_string($value)) {
        throw new InvalidArgumentException($message);
    }

    return $value;
}

function posted_string(string $name): string
{
    $value = $_POST[$name] ?? '';

    return is_string($value) ? $value : '';
}

function valid_continue_url(mixed $value): string
{
    if (!is_string($value)
        || preg_match('/^vote\.php\?event_id=[1-9]\d*&category_id=[1-9]\d*&contestant_id=[1-9]\d*$/D', $value) !== 1) {
        return '';
    }

    return $value;
}

$action = $_GET['action'] ?? 'login';
$continueUrl = valid_continue_url($_POST['continue'] ?? $_GET['continue'] ?? '');
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    $currentUser = $_SESSION['user'] ?? null;
    echo json_encode([
        'authenticated' => is_array($currentUser),
        'user' => is_array($currentUser) ? [
            'full_name' => (string) ($currentUser['full_name'] ?? ''),
            'email' => (string) ($currentUser['email'] ?? ''),
            'roles' => is_array($currentUser['roles'] ?? null) ? $currentUser['roles'] : [],
            'organizer_status' => $currentUser['organizer_status'] ?? null,
        ] : null,
        'csrf_token' => is_array($currentUser) ? csrf_token() : null,
    ], JSON_THROW_ON_ERROR);
    exit;
}
if (!in_array($action, ['login', 'register', 'logout', 'account'], true)) {
    http_response_code(404);
    exit('Page not found.');
}
if ($action === 'account' && !isset($_SESSION['user'])) {
    redirect_to('auth.php?action=login');
}

$errorMessage = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['_token'] ?? null);

        if ($action === 'logout') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httponly'],
                    'samesite' => $cookie['samesite'] ?? 'Strict',
                ]);
            }
            session_destroy();
            redirect_to('index.php');
        }

        if ($action === 'register') {
            $email = strtolower(trim(post_string('email', '', 'Enter a valid email address.')));
            $fullName = trim(post_string(
                'full_name',
                '',
                'Enter your name using no more than 160 characters.'
            ));
            $password = post_string('password', '', 'Use a password between 12 and 72 bytes long.');
            $passwordConfirmation = post_string(
                'password_confirmation',
                '',
                'The password confirmation does not match.'
            );
            $accountType = post_string('account_type', 'voter', 'Choose a supported account type.');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                throw new InvalidArgumentException('Enter a valid email address.');
            }
            if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 160) {
                throw new InvalidArgumentException('Enter your name using no more than 160 characters.');
            }
            if (strlen($password) < 12 || strlen($password) > 72) {
                throw new InvalidArgumentException('Use a password between 12 and 72 bytes long.');
            }
            if (!hash_equals($password, $passwordConfirmation)) {
                throw new InvalidArgumentException('The password confirmation does not match.');
            }

            (new AuthService(database_connection()))->register(
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $fullName,
                $accountType
            );
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $successMessage = $accountType === 'organizer'
                ? 'Your account is ready. Organizer access remains pending platform approval.'
                : 'Your account is ready. You can now sign in.';
            $action = 'login';
        } elseif ($action === 'login') {
            $email = strtolower(trim(post_string('email', '', 'Enter your email address and password.')));
            $password = post_string('password', '', 'Enter your email address and password.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
                throw new InvalidArgumentException('Enter your email address and password.');
            }

            $user = (new AuthService(database_connection()))->authenticate($email, $password);
            if ($user === null) {
                throw new DomainException('The email address or password is incorrect, or the account is not active.');
            }

            session_regenerate_id(true);
            $roles = $user['roles'] === null ? [] : explode(',', $user['roles']);
            $_SESSION['user'] = [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'roles' => $roles,
                'organizer_status' => $user['organizer_status'],
            ];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            if (in_array('super_admin', $roles, true)) {
                redirect_to('admin/index.php');
            }
            if ($continueUrl !== '') {
                redirect_to($continueUrl);
            }
            $successMessage = 'You are signed in.';
        }
    } catch (PDOException $exception) {
        error_log('WInVote authentication database error: ' . $exception->getMessage());
        http_response_code(503);
        $errorMessage = 'Authentication is temporarily unavailable. Please try again later.';
    } catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
        $errorMessage = $exception->getMessage();
    }
}

$signedInUser = $_SESSION['user'] ?? null;
$isRegister = $action === 'register';
$title = $isRegister ? 'Create your account' : 'Welcome back';
$description = $isRegister
    ? 'Create an account to participate or request organizer access.'
    : 'Sign in to continue to your WInVote account.';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= escape_html($title) ?> — WInVote</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <?= render_public_header(is_array($signedInUser) ? $signedInUser : null, csrf_token(), '') ?>
  <main class="content-section">
    <div class="container">
      <div class="form-shell">
        <section class="form-card">
          <h1><?= escape_html($signedInUser === null ? $title : 'Account details') ?></h1>
          <p class="form-intro"><?= escape_html($signedInUser === null ? $description : $signedInUser['full_name'] . ' · ' . $signedInUser['email']) ?></p>
          <?php if ($errorMessage !== null): ?>
            <div class="notice warning" role="alert"><?= escape_html($errorMessage) ?></div>
          <?php endif; ?>
          <?php if ($successMessage !== null): ?>
            <div class="notice" role="status"><?= escape_html($successMessage) ?></div>
          <?php endif; ?>

          <?php if ($signedInUser !== null): ?>
            <?php if (in_array('organizer', $signedInUser['roles'], true)): ?>
              <p class="field-hint">Organizer account status: <?= escape_html((string) ($signedInUser['organizer_status'] ?? 'pending')) ?>.</p>
            <?php endif; ?>
            <ul class="detail-list account-details">
              <li><span>Account name</span><strong><?= escape_html((string) $signedInUser['full_name']) ?></strong></li>
              <li><span>Email address</span><strong><?= escape_html((string) $signedInUser['email']) ?></strong></li>
              <li><span>Account role</span><strong><?= escape_html(in_array('super_admin', $signedInUser['roles'], true) ? 'Super admin' : (in_array('organizer', $signedInUser['roles'], true) ? 'Organizer' : 'Voter')) ?></strong></li>
            </ul>
            <form method="post" action="auth.php?action=logout" style="margin-top:20px">
              <input type="hidden" name="_token" value="<?= escape_html(csrf_token()) ?>">
              <button class="btn btn-primary" type="submit">Sign out</button>
            </form>
          <?php else: ?>
            <form method="post" action="auth.php?action=<?= $isRegister ? 'register' : 'login' ?>">
              <input type="hidden" name="_token" value="<?= escape_html(csrf_token()) ?>">
              <?php if ($continueUrl !== ''): ?><input type="hidden" name="continue" value="<?= escape_html($continueUrl) ?>"><?php endif; ?>
              <?php if ($isRegister): ?>
                <div class="field"><label for="full_name">Full name</label><input class="input" id="full_name" name="full_name" autocomplete="name" required maxlength="160" value="<?= escape_html(posted_string('full_name')) ?>"></div>
              <?php endif; ?>
              <div class="field"><label for="email">Email address</label><input class="input" type="email" id="email" name="email" autocomplete="email" required maxlength="254" value="<?= escape_html(posted_string('email')) ?>"></div>
              <?php if ($isRegister): ?>
                <div class="field"><label for="account_type">Account type</label><select class="select" style="width:100%" id="account_type" name="account_type"><option value="voter">Participate as a voter</option><option value="organizer">Request organizer access</option></select></div>
              <?php endif; ?>
              <div class="field"><label for="password"><?= $isRegister ? 'Create password' : 'Password' ?></label><input class="input" type="password" id="password" name="password" autocomplete="<?= $isRegister ? 'new-password' : 'current-password' ?>" required <?= $isRegister ? 'minlength="12" maxlength="72"' : '' ?>></div>
              <?php if ($isRegister): ?>
                <div class="field"><label for="password_confirmation">Confirm password</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required></div>
              <?php endif; ?>
              <button class="btn btn-primary" type="submit"><?= $isRegister ? 'Create account' : 'Log in' ?></button>
            </form>
            <p class="form-note">
              <?php if ($isRegister): ?>
                Already have an account? <a class="text-link" href="auth.php?action=login">Log in</a>
              <?php else: ?>
                New to WInVote? <a class="text-link" href="auth.php?action=register<?= $continueUrl === '' ? '' : '&amp;continue=' . rawurlencode($continueUrl) ?>">Create an account</a>
              <?php endif; ?>
            </p>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>
  <script>
    document.querySelectorAll('form').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (form.action.includes('action=register')) {
          const password = form.querySelector('[name="password"]');
          const confirmation = form.querySelector('[name="password_confirmation"]');
          if (password.value !== confirmation.value) {
            event.preventDefault();
            confirmation.setCustomValidity('The password confirmation does not match.');
            confirmation.reportValidity();
          } else {
            confirmation.setCustomValidity('');
          }
        }
      });
    });
  </script>
</body>
</html>
