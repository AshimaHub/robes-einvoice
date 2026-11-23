<h2>Sign in</h2>
<p class="lede"><?= e($brand['company'] !== '' ? 'Welcome back to ' . $brand['company'] . '.' : 'Sign in to your company account.') ?></p>
<?php if (!empty($error)): ?>
<div class="flash danger"><?= e($error) ?></div>
<?php endif; ?>
<form method="post" class="stack">
    <?= robes_csrf_field() ?>
    <label>Email<input type="email" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary" type="submit">Sign in</button>
</form>
