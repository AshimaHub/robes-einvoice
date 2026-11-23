<h2>Create your account</h2>
<p class="lede">This is your company’s invoicing desk. After you register, you choose sandbox or production and connect it to NRS.</p>
<?php if (!empty($errors)): ?>
<div class="flash danger"><?= e(implode(' ', $errors)) ?></div>
<?php endif; ?>
<form method="post" class="stack">
    <?= robes_csrf_field() ?>
    <label>Your name<input name="name" required value="<?= e($_POST['name'] ?? '') ?>"></label>
    <label>Work email<input type="email" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Company legal name<input name="company" required value="<?= e($_POST['company'] ?? '') ?>" placeholder="As it should appear on invoices"></label>
    <div class="grid-2">
        <label>Password<input type="password" name="password" required autocomplete="new-password" minlength="8"></label>
        <label>Confirm<input type="password" name="password_confirm" required autocomplete="new-password" minlength="8"></label>
    </div>
    <button class="btn primary" type="submit">Create account</button>
</form>
