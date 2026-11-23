<section class="card">
    <h2>Add someone</h2>
    <form method="post" class="stack">
        <?= robes_csrf_field() ?>
        <div class="grid-2">
            <label>Name<input name="name" required></label>
            <label>Email<input type="email" name="email" required></label>
        </div>
        <div class="grid-2">
            <label>Password<input type="password" name="password" required minlength="8"></label>
            <label>Role
                <select name="role">
                    <option value="operator">Operator — invoices and customers</option>
                    <option value="admin">Administrator — settings and team</option>
                </select>
            </label>
        </div>
        <button class="btn primary" type="submit">Add</button>
    </form>
</section>
<section class="card">
    <h2>People who can sign in</h2>
    <?php foreach ($rows as $row): ?>
    <form method="post" action="<?= e(robes_url('/users/' . (int) $row['id'])) ?>" class="person">
        <?= robes_csrf_field() ?>
        <div>
            <strong><?= e($row['name']) ?></strong>
            <p class="muted"><?= e($row['email']) ?></p>
        </div>
        <label>Role
            <select name="role">
                <option value="operator" <?= $row['role'] === 'operator' ? 'selected' : '' ?>>Operator</option>
                <option value="admin" <?= $row['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
            </select>
        </label>
        <label class="check"><input type="checkbox" name="active" value="1" <?= (int) $row['active'] === 1 ? 'checked' : '' ?>> Active</label>
        <label>New password<input type="password" name="password" placeholder="Leave blank to keep" autocomplete="new-password"></label>
        <button class="btn" type="submit">Update</button>
    </form>
    <?php endforeach; ?>
</section>
